<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    |
    | Set some default values. It is possible to add all defines that can be set
    | in dompdf_config.inc.php. You can also override the global variables.
    |
    */
    'show_warnings' => false,   // Useful for debugging to see warnings
    'public_path' => null,      // Override the public path if needed
    'convert_entities' => true,

    'options' => [
        /**
         * The location of the DOMPDF font directory
         *
         * The location of the directory where DOMPDF will store fonts and font metrics
         * Note: This directory must exist and be writable by the webserver process.
         * *Please note the trailing slash.*
         *
         * Notes regarding fonts:
         * Additional .afm font metrics can be added by executing load_font.php from command line.
         * Only the original "Base 14 fonts" are present by default.
         */
        'font_dir' => storage_path('fonts'), // fonts directory in storage

        /**
         * The location of the DOMPDF font cache directory
         *
         * This directory must exist and be writable by the webserver process.
         * *Please note the trailing slash.*
         */
        'font_cache' => storage_path('fonts'),

        /**
         * The temporary directory.
         *
         * The location of the directory where DOMPDF will store temporary files.
         * This directory must exist and be writable by the webserver process.
         */
        'temp_dir' => sys_get_temp_dir(),

        /**
         * DOMPDF_CHROOT
         *
         * Prevents DOMPDF from accessing system files or other files on the webserver.
         * All local files must be under this directory (or any of its subdirectories).
         *
         * null: Checks the project folder
         */
        'chroot' => realpath(base_path()),

        /**
         * Enable embedded PHP
         *
         * If this setting is set to true then DOMPDF will automatically evaluate
         * embedded PHP contained within <script type="text/php"> ... </script> tags.
         *
         * Enable this setting with caution!
         */
        'enable_php' => false,

        /**
         * Enable Javascript
         *
         * If this setting is set to true then DOMPDF will automatically run
         * embedded Javascript contained within <script type="text/javascript"> ... </script> tags.
         */
        'enable_javascript' => true,

        /**
         * Enable remote file access
         *
         * If this setting is set to true, DOMPDF will accept remote filepaths
         * (pixels, script, css) via file_get_contents
         *
         * This can be a security risk!
         */
        'enable_remote' => true,

        /**
         * A ratio applied to the fonts height to be more like browsers' line height
         */
        'font_height_ratio' => 1.1,

        /**
         * Enable/Disable subsetting
         * * Important for Persian/Arabic fonts to reduce file size
         */
        'enable_font_subsetting' => true,

        /**
         * Set the default character encoding
         */
        'def' => "utf-8",
    ],
];
