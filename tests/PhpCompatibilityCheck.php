<?php

set_error_handler(function ($severity, $message, $file, $line) {
    if (($severity & E_DEPRECATED) !== 0) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

try {
    require_once __DIR__ . '/../src/Common/UniversalApi.php';
} finally {
    restore_error_handler();
}

echo "PHP compatibility check passed.\n";
