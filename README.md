# laravel-pdf-order-parser
Laravel package that converts raw PDF transport orders into structured arrays matching a JSON schema. Each PDF format is parsed by its own App\Assistants class extending PdfClient, with automatic format detection and schema-compliant output via process_pdf().
