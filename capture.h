#ifndef DECRYPT_CAPTURE_H
#define DECRYPT_CAPTURE_H

int decrypt_capture_init(void);
void decrypt_capture_shutdown(void);
void decrypt_capture_source(const char *filename, const char *bytes, size_t length, int is_eval);

#endif
