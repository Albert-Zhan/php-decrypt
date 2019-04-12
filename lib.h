/**
  +----------------------------------------------------------------------+
  | PHP Version 7                                                        |
  +----------------------------------------------------------------------+
  | Copyright (c) 1997-2017 The PHP Group                                |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Albert Zhan  www.5lazy.cn   github.com/2654709623/php-decrypt               |
  +----------------------------------------------------------------------+
*/

#ifndef LIB_H
#define LIB_H

#ifdef PHP_WIN32
#include <io.h>
#include <direct.h>
#else
#include <unistd.h>
#include <sys/types.h>
#include <sys/stat.h>
#endif

#ifdef PHP_WIN32
#   define DS "\"
#else
#   define DS "/"
#endif

#ifndef PHP_WIN32

void _splitpath(const char *path, char *drive, char *dir, char *fname, char *ext);

static void _split_whole_name(const char *whole_name, char *fname, char *ext);

void _splitpath(const char *path, char *drive, char *dir, char *fname, char *ext)
{
    char *p_whole_name;

    drive[0] = '\0';
    if (NULL == path)
    {
        dir[0] = '\0';
        fname[0] = '\0';
        ext[0] = '\0';
        return;
    }

    if ('/' == path[strlen(path)])
    {
        strcpy(dir, path);
        fname[0] = '\0';
        ext[0] = '\0';
        return;
    }

    p_whole_name = rindex(path, '/');
    if (NULL != p_whole_name)
    {
        p_whole_name++;
        _split_whole_name(p_whole_name, fname, ext);

        snprintf(dir, p_whole_name - path, "%s", path);
    }
    else
    {
        _split_whole_name(path, fname, ext);
        dir[0] = '\0';
    }
}

static void _split_whole_name(const char *whole_name, char *fname, char *ext)
{
    char *p_ext;

    p_ext = rindex(whole_name, '.');
    if (NULL != p_ext)
    {
        strcpy(ext, p_ext);
        snprintf(fname, p_ext - whole_name + 1, "%s", whole_name);
    }
    else
    {
        ext[0] = '\0';
        strcpy(fname, whole_name);
    }
}

#endif

int is_dirs(const char* _Filename);

int is_dirs(const char* _Filename)
{
    return access(_Filename,F_OK);
}

int mkdirs(const char *pathname);

int mkdirs(const char *pathname)
{
    #ifdef PHP_WIN32
    return mkdir(pathname);
    #else
    return mkdir(pathname,S_IRWXU|S_IRGRP|S_IXGRP|S_IROTH|S_IXOTH);
    #endif
}

#endif
