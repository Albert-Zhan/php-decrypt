/* Source storage for PHP Decrypt. See LICENSE (Apache-2.0). */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_decrypt.h"
#include "capture.h"
#include "main/fopen_wrappers.h"
#include "main/php_open_temporary_file.h"
#include "ext/hash/php_hash.h"
#include "ext/hash/php_hash_sha.h"
#include "ext/json/php_json.h"
#include "ext/standard/flock_compat.h"
#include "ext/standard/base64.h"
#include "Zend/zend_smart_str.h"
#include <errno.h>
#include <fcntl.h>
#include <time.h>
#ifdef PHP_WIN32
#include <io.h>
#include <process.h>
#else
#include <unistd.h>
#endif

static void digest_hex(const char *bytes, size_t length, char out[65])
{
    PHP_SHA256_CTX ctx;
    unsigned char digest[32];
    static const char hex[] = "0123456789abcdef";
    size_t i;
    PHP_SHA256Init(&ctx);
    PHP_SHA256Update(&ctx, (const unsigned char *) bytes, length);
    PHP_SHA256Final(digest, &ctx);
    for (i = 0; i < 32; ++i) {
        out[i * 2] = hex[digest[i] >> 4];
        out[i * 2 + 1] = hex[digest[i] & 15];
    }
    out[64] = '\0';
}

static void normalize_path(char *path)
{
#ifdef PHP_WIN32
    for (; *path; ++path) if (*path == '\\') *path = '/';
#else
    (void) path;
#endif
}

static int within(const char *path, const char *root)
{
    size_t n = strlen(root);
#ifdef PHP_WIN32
    int match = strncasecmp(path, root, n) == 0;
#else
    int match = strncmp(path, root, n) == 0;
#endif
    return match && (root[n - 1] == '/' || path[n] == '/' || path[n] == '\0');
}

static char *join_path(const char *left, const char *right)
{
    char *result;
    spprintf(&result, 0, "%s/%s", left, right);
    return result;
}

static char *parent_path(const char *path)
{
    const char *slash = strrchr(path, '/');
    return slash ? estrndup(path, slash == path ? 1 : (size_t)(slash - path)) : estrdup(".");
}

/* The output directory must not be writable by untrusted processes. Reject
 * existing symlinks component by component, including source-tree mirrors. */
static int checked_path(const char *path)
{
    char *copy, *p;
    zend_stat_t sb;
    int result = FAILURE;
    if (php_check_open_basedir(path)) return FAILURE;
    copy = estrdup(path);
    for (p = copy + 1; ; ++p) {
        char saved;
        if (*p != '/' && *p != '\0') continue;
        saved = *p;
        *p = '\0';
        errno = 0;
        if (VCWD_LSTAT(copy, &sb) == 0) {
#ifdef S_ISLNK
            if (S_ISLNK(sb.st_mode)) goto done;
#endif
            if (saved && !S_ISDIR(sb.st_mode)) goto done;
        } else if (errno != ENOENT) {
            goto done;
        }
        *p = saved;
        if (!saved) break;
    }
    result = SUCCESS;
done:
    efree(copy);
    return result;
}

static int sync_directory(const char *directory)
{
    int result = SUCCESS;
    if (!DCG(sync)) return SUCCESS;
#ifdef PHP_WIN32
    (void) directory;
    result = FAILURE;
#else
    int fd = VCWD_OPEN(directory, O_RDONLY | O_DIRECTORY);
    if (fd < 0) return FAILURE;
    if (fsync(fd) != 0) result = FAILURE;
    if (close(fd) != 0) result = FAILURE;
#endif
    return result;
}

static int sync_stream(php_stream *stream)
{
    int fd;
    if (php_stream_flush(stream) != 0) return FAILURE;
    if (!DCG(sync)) return SUCCESS;
    if (php_stream_cast(stream, PHP_STREAM_AS_FD, (void *) &fd, 0) != SUCCESS) return FAILURE;
#ifdef PHP_WIN32
    return _commit(fd) == 0 ? SUCCESS : FAILURE;
#else
    return fsync(fd) == 0 ? SUCCESS : FAILURE;
#endif
}

static int ensure_directory(const char *directory)
{
    zend_stat_t sb;
    char *parent;
    int result;
    if (checked_path(directory) != SUCCESS) return FAILURE;
    if (VCWD_STAT(directory, &sb) == 0) return S_ISDIR(sb.st_mode) ? SUCCESS : FAILURE;
    parent = parent_path(directory);
    if (!strcmp(parent, directory) || ensure_directory(parent) != SUCCESS) {
        efree(parent);
        return FAILURE;
    }
    result = VCWD_MKDIR(directory, 0700);
    if (result != 0 && (errno != EEXIST || VCWD_STAT(directory, &sb) != 0 || !S_ISDIR(sb.st_mode))) {
        efree(parent);
        return FAILURE;
    }
    result = sync_directory(parent);
    efree(parent);
    return result;
}

static int regular_size(const char *path, size_t length)
{
    zend_stat_t sb;
    return checked_path(path) == SUCCESS && VCWD_LSTAT(path, &sb) == 0
        && S_ISREG(sb.st_mode) && sb.st_size >= 0 && (size_t) sb.st_size == length;
}

static int write_all(php_stream *stream, const char *bytes, size_t length)
{
    size_t offset = 0;
    while (offset < length) {
        size_t chunk = length - offset;
        zend_long written;
        if (chunk > 1024 * 1024) chunk = 1024 * 1024;
        written = (zend_long) php_stream_write(stream, bytes + offset, chunk);
        if (written <= 0) return FAILURE;
        offset += (size_t) written;
    }
    return SUCCESS;
}

/* Publish only after every byte has been written and the stream closed.
 * Temp files are created in the target directory, never across filesystems. */
static int atomic_write(const char *path, const char *bytes, size_t length)
{
    char *parent = parent_path(path);
    zend_string *temporary = NULL;
    php_stream *stream = NULL;
    int fd, result = FAILURE;
    if (ensure_directory(parent) != SUCCESS || checked_path(path) != SUCCESS) goto done;
#ifdef PHP_TMP_FILE_OPEN_BASEDIR_CHECK_ALWAYS
    fd = php_open_temporary_fd_ex(parent, ".decrypt-", &temporary,
        PHP_TMP_FILE_OPEN_BASEDIR_CHECK_ALWAYS | PHP_TMP_FILE_SILENT);
#else
    fd = php_open_temporary_fd_ex(parent, ".decrypt-", &temporary, 1);
#endif
    if (fd < 0) goto done;
    stream = php_stream_fopen_from_fd(fd, "wb", NULL);
    if (!stream) {
#ifdef PHP_WIN32
        _close(fd);
#else
        close(fd);
#endif
        goto done;
    }
    {
        char *actual_parent = parent_path(ZSTR_VAL(temporary));
        int same_directory = strcmp(parent, actual_parent) == 0;
        efree(actual_parent);
        if (!same_directory) goto done;
    }
    if (write_all(stream, bytes, length) != SUCCESS || sync_stream(stream) != SUCCESS) goto done;
    if (php_stream_close(stream) != 0) {
        stream = NULL;
        goto done;
    }
    stream = NULL;
    if (checked_path(path) != SUCCESS || VCWD_RENAME(ZSTR_VAL(temporary), path) != 0) goto done;
    result = sync_directory(parent);
done:
    if (stream) php_stream_close(stream);
    if (temporary) {
        VCWD_UNLINK(ZSTR_VAL(temporary));
        zend_string_release(temporary);
    }
    efree(parent);
    return result;
}

static php_stream *lock_path(const char *path)
{
    php_stream *stream;
    if (checked_path(path) != SUCCESS) return NULL;
    {
        zend_stat_t sb;
        if (VCWD_LSTAT(path, &sb) == 0 && !S_ISREG(sb.st_mode)) return NULL;
    }
    stream = php_stream_open_wrapper((char *) path, "c+b", 0, NULL);
    if (!stream) return NULL;
    if (php_stream_lock(stream, LOCK_EX) != 0) {
        php_stream_close(stream);
        return NULL;
    }
    return stream;
}

static void unlock_path(php_stream *stream)
{
    php_stream_lock(stream, LOCK_UN);
    php_stream_close(stream);
}

/* Discard an interrupted final JSONL record under the append lock. Completed
 * records are never scanned on the normal path. */
static int repair_index_tail(php_stream *stream)
{
    zend_off_t end, pos;
    char byte;
    if (php_stream_seek(stream, 0, SEEK_END) != 0) return FAILURE;
    end = php_stream_tell(stream);
    if (end < 0) return FAILURE;
    if (!end) return SUCCESS;
    pos = end;
    while (pos > 0) {
        --pos;
        if (php_stream_seek(stream, pos, SEEK_SET) != 0 || php_stream_read(stream, &byte, 1) != 1) return FAILURE;
        if (byte == '\n') break;
    }
    if (byte != '\n') pos = -1;
    if (pos + 1 != end && php_stream_truncate_set_size(stream, (size_t)(pos + 1)) != 0) return FAILURE;
    return php_stream_seek(stream, 0, SEEK_END) == 0 ? SUCCESS : FAILURE;
}

static int append_index(const char *relative, const char *filename, const char *hash,
    size_t length, int is_eval, const char *object_relative)
{
    char *index = join_path(DCG(output_root), "index.jsonl");
    php_stream *stream = lock_path(index);
    smart_str json = {0};
    zval record;
    php_json_error_code saved_json_error;
    zend_off_t start = -1;
    int result = FAILURE;
    if (!stream) { efree(index); return FAILURE; }
    array_init(&record);
    add_assoc_long(&record, "schema", 1);
    add_assoc_string(&record, "kind", is_eval ? "eval" : "file");
    add_assoc_string(&record, "source_path", (char *) filename);
    add_assoc_string(&record, "relative_path", (char *) relative);
    add_assoc_str(&record, "source_path_b64", php_base64_encode((const unsigned char *) filename, strlen(filename)));
    add_assoc_str(&record, "relative_path_b64", php_base64_encode((const unsigned char *) relative, strlen(relative)));
    add_assoc_string(&record, "sha256", (char *) hash);
    add_assoc_long(&record, "bytes", (zend_long) length);
    add_assoc_long(&record, "captured_at", (zend_long) time(NULL));
    add_assoc_long(&record, "pid", (zend_long) getpid());
    if (object_relative) add_assoc_string(&record, "object", (char *) object_relative);
    else add_assoc_null(&record, "object");
    saved_json_error = JSON_G(error_code);
    /* Invalid UTF-8 filenames must not produce malformed JSONL. */
#if PHP_VERSION_ID >= 70200
    php_json_encode(&json, &record, PHP_JSON_INVALID_UTF8_SUBSTITUTE);
#else
    php_json_encode(&json, &record, PHP_JSON_PARTIAL_OUTPUT_ON_ERROR);
#endif
    JSON_G(error_code) = saved_json_error;
    zval_ptr_dtor(&record);
    smart_str_0(&json);
    if (!json.s || !ZSTR_LEN(json.s) || ZSTR_VAL(json.s)[0] != '{') goto done;
    smart_str_appendc(&json, '\n');
    if (repair_index_tail(stream) != SUCCESS) goto done;
    start = php_stream_tell(stream);
    if (write_all(stream, ZSTR_VAL(json.s), ZSTR_LEN(json.s)) != SUCCESS || sync_stream(stream) != SUCCESS) {
        /* Remove a short append before releasing the writer lock. */
        if (start >= 0) {
            php_stream_truncate_set_size(stream, (size_t) start);
            php_stream_flush(stream);
        }
        goto done;
    }
    result = sync_directory(DCG(output_root));
done:
    smart_str_free(&json);
    unlock_path(stream);
    efree(index);
    return result;
}

int decrypt_capture_init(void)
{
    char project[MAXPATHLEN], output[MAXPATHLEN];
    zend_stat_t sb;
    size_t n;
    if (DCG(ready)) return SUCCESS;
    if (DCG(config_failed)) return FAILURE;
    DCG(config_failed) = 1;
    if (!DCG(project_root) || !IS_ABSOLUTE_PATH(DCG(project_root), strlen(DCG(project_root)))
        || !VCWD_REALPATH(DCG(project_root), project)) goto invalid;
    if (!DCG(save_path) || !IS_ABSOLUTE_PATH(DCG(save_path), strlen(DCG(save_path)))
        || checked_path(DCG(save_path)) != SUCCESS || !VCWD_REALPATH(DCG(save_path), output)) goto invalid;
    if (VCWD_STAT(project, &sb) != 0 || !S_ISDIR(sb.st_mode)
        || VCWD_STAT(output, &sb) != 0 || !S_ISDIR(sb.st_mode)) goto invalid;
#ifdef PHP_WIN32
    if (DCG(sync)) goto invalid;
#endif
    normalize_path(project);
    normalize_path(output);
    n = strlen(project);
    while (n > 1 && project[n - 1] == '/') project[--n] = '\0';
    n = strlen(output);
    while (n > 1 && output[n - 1] == '/') output[--n] = '\0';
    if (within(project, output)) goto invalid;
    DCG(input_root) = estrdup(project);
    DCG(output_root) = estrdup(output);
    if (ensure_directory(DCG(output_root)) != SUCCESS) goto invalid;
    {
        const char *directories[] = {"current", "locks", NULL};
        int i;
        for (i = 0; directories[i]; ++i) {
            char *path = join_path(output, directories[i]);
            int ok = ensure_directory(path);
            efree(path);
            if (ok != SUCCESS) goto invalid;
        }
    }
    DCG(ready) = 1;
    DCG(config_failed) = 0;
    return SUCCESS;
invalid:
    php_error_docref(NULL, E_WARNING,
        "decrypt: project_root and save_path must be existing absolute directories; "
        "save_path must not contain project_root and output paths must be writable, allowed by open_basedir, and free of symlinks");
    return FAILURE;
}

void decrypt_capture_shutdown(void)
{
    if (DCG(input_root)) efree(DCG(input_root));
    if (DCG(output_root)) efree(DCG(output_root));
    DCG(input_root) = DCG(output_root) = NULL;
    DCG(busy) = DCG(ready) = DCG(config_failed) = 0;
}

static void capture_source_impl(const char *filename, const char *bytes, size_t length, int is_eval)
{
    char absolute[MAXPATHLEN], hash[65], key[65], expected[80], previous[80];
    char *relative = NULL, *current = NULL, *lock_name = NULL, *lock_file = NULL;
    char *object = NULL, *object_relative = NULL, *locks_directory = NULL;
    php_stream *lock = NULL;
    int result = FAILURE, previous_length;
    size_t root_length;
    if (!filename || decrypt_capture_init() != SUCCESS) return;
    if (is_eval) {
        /* Only capture evals whose containing file is inside project_root. */
        const char *marker = strstr(filename, ") : eval()'d code");
        const char *begin = marker;
        char *origin;
        if (begin) {
            while (begin > filename && begin[-1] >= '0' && begin[-1] <= '9') --begin;
            if (begin > filename && begin[-1] == '(') --begin;
            else begin = NULL;
        }
        origin = begin ? estrndup(filename, (size_t)(begin - filename)) : estrdup(filename);
        int resolved = VCWD_REALPATH(origin, absolute) != NULL;
        efree(origin);
        if (!resolved) return;
    } else if (!VCWD_REALPATH(filename, absolute)) return;
    normalize_path(absolute);
    if (!within(absolute, DCG(input_root)) || within(absolute, DCG(output_root))) return;
    digest_hex(bytes, length, hash);
    if (is_eval) {
        digest_hex(filename, strlen(filename), key);
        spprintf(&relative, 0, "eval/%s/%s.code", key, hash);
    } else {
        root_length = strlen(DCG(input_root));
        while (absolute[root_length] == '/') ++root_length;
        spprintf(&relative, 0, "current/%s", absolute + root_length);
    }
    digest_hex(relative, strlen(relative), key);
    current = join_path(DCG(output_root), relative);
    spprintf(&lock_name, 0, "locks/%s.lock", key);
    lock_file = join_path(DCG(output_root), lock_name);
    locks_directory = join_path(DCG(output_root), "locks");
    lock = lock_path(lock_file);
    if (!lock) goto done;
    snprintf(expected, sizeof(expected), "%s:%d:%d\n", hash, !!DCG(history), !!DCG(index));
    previous_length = (int) php_stream_read(lock, previous, sizeof(previous) - 1);
    if (previous_length < 0) previous_length = 0;
    previous[previous_length] = '\0';
    if (DCG(history)) {
        spprintf(&object_relative, 0, "objects/%.2s/%s.code", hash, hash);
        object = join_path(DCG(output_root), object_relative);
    }
    /* The state is published last. Missing output files trigger recovery on
     * the next capture, even if an earlier process recorded this digest. */
    if (!strcmp(previous, expected) && regular_size(current, length)
        && (!object || regular_size(object, length))) {
        char *index_path = join_path(DCG(output_root), "index.jsonl");
        zend_stat_t sb;
        int index_ok = !DCG(index) || (checked_path(index_path) == SUCCESS
            && VCWD_STAT(index_path, &sb) == 0 && S_ISREG(sb.st_mode) && sb.st_size > 0);
        efree(index_path);
        if (index_ok) { result = SUCCESS; goto done; }
    }
    if (object && !regular_size(object, length) && atomic_write(object, bytes, length) != SUCCESS) goto done;
    if (atomic_write(current, bytes, length) != SUCCESS) goto done;
    if (DCG(index) && append_index(relative, filename, hash, length, is_eval, object_relative) != SUCCESS) goto done;
    if (php_stream_seek(lock, 0, SEEK_SET) != 0
        || write_all(lock, expected, strlen(expected)) != SUCCESS
        || php_stream_truncate_set_size(lock, strlen(expected)) != 0
        || sync_stream(lock) != SUCCESS || sync_directory(locks_directory) != SUCCESS) goto done;
    result = SUCCESS;
done:
    if (lock) unlock_path(lock);
    if (result != SUCCESS) php_error_docref(NULL, E_WARNING, "decrypt: unable to publish capture for %s", filename);
    if (relative) efree(relative);
    if (current) efree(current);
    if (lock_name) efree(lock_name);
    if (lock_file) efree(lock_file);
    if (locks_directory) efree(locks_directory);
    if (object) efree(object);
    if (object_relative) efree(object_relative);
}

/* A warning handler can include or eval PHP. Never recursively enter storage
 * while holding one of its writer locks. */
void decrypt_capture_source(const char *filename, const char *bytes, size_t length, int is_eval)
{
    if (DCG(busy)) return;
    DCG(busy) = 1;
    capture_source_impl(filename, bytes, length, is_eval);
    DCG(busy) = 0;
}
