<?php

return [

    'time_budget' => (int) env('IMPORT_TIME_BUDGET', 20),

    'batch_size' => (int) env('IMPORT_BATCH_SIZE', 1000),

    'max_file_size' => (int) env('IMPORT_MAX_FILE_SIZE', 100 * 1024 * 1024),

    'max_chunk_size' => (int) env('IMPORT_MAX_CHUNK_SIZE', 1024),

];
