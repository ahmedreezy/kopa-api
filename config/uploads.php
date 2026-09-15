<?php

return [
    'document_max_mb' => (int) env('MAX_DOCUMENT_UPLOAD_MB', 10),
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
];
