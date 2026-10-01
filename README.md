# Lead import from xlsx

**English** · [Українська](README.uk.md)

A web page that imports leads from an xlsx file into MySQL.

The supplied file has 100,000 rows and weighs 11 MB. The requirement is that every row ends up in the database while PHP runs with its stock `max_execution_time=30`. This project keeps the whole PHP configuration stock, not just that one directive:

| Setting               | Value |
|-----------------------|-------|
| `max_execution_time`  | 30    |
| `upload_max_filesize` | 2M    |
| `post_max_size`       | 8M    |
| `memory_limit`        | 128M  |

nginx also keeps its default `client_max_body_size` of 1 MB.

On the supplied file the import takes about 23 seconds and writes exactly 100,000 rows.

## Stack

- Laravel 13, PHP 8.4 (php-fpm)
- Vue 3, Vite 8, Tailwind CSS 4
- MySQL 8.4
- nginx
- Laravel queue on the `database` driver
- Docker Compose

No third-party PHP library is used to read xlsx — only the built-in `zip` and `xmlreader` extensions.

## Running

Docker with Docker Compose is the only prerequisite.

```bash
docker compose up -d --build
```

The import page is then available at <http://localhost:8080>.

To use another port: `APP_PORT=8090 docker compose up -d --build`. Other parameters (database name, passwords, batch size) are listed in `.env.example`; copy it to `.env` if you need to change them. The `.env` file is optional — every parameter has a default.

| Service | Purpose |
|---------|---------|
| `web`   | nginx: serves static files and proxies PHP requests to php-fpm |
| `app`   | php-fpm; on start it generates `APP_KEY` and runs migrations |
| `queue` | queue worker (`queue:work --timeout=30`) that performs the import |
| `db`    | MySQL 8.4 with its own volume; the port is not published to the host |

All services run in a dedicated network, `soimport-network`. Database data and uploaded files live in the `db-data` and `app-storage` volumes.

Stop: `docker compose down`. Stop and delete data: `docker compose down -v`.

## Tests

```bash
docker compose run --rm tests
```

The tests run in a separate container with no network, on in-memory SQLite. Do not run `php artisan test` inside the `app` container: its environment variables point at the working MySQL database and override `phpunit.xml`.

## How it works

### 1. Chunked upload

The browser slices the file into 512 KB chunks and sends them one by one to `POST /imports/chunks`. This is what lets an 11 MB file through the stock upload limits. After the last chunk, `POST /imports` joins the chunks, checks that the result is a real xlsx (a zip archive containing `xl/workbook.xml`), creates a row in `imports` and queues a job. The request returns immediately.

### 2. Streaming read

An xlsx file is a zip archive of XML files. `XlsxSheetReaderService` reads the sheet XML straight from the archive with `XMLReader` over the `zip://` wrapper, one row at a time. The file is never unpacked to disk or loaded into memory, so peak memory usage is about 30 MB even though the unpacked sheet is 61 MB. Most of that memory is the shared-strings table, which has to be held in full.

The column of a cell is taken from its address (`K7`), not from its position, because empty cells are simply absent from the XML.

### 3. Import in time-boxed steps

`ImportLeadsJob` runs with a budget of 20 seconds. After every inserted batch it checks the clock; once the budget is spent it stops and queues an identical job, which resumes from the saved cursor. The job also has a hard 30-second timeout measured in wall-clock time.

The queue worker is a CLI process, where `max_execution_time` is unlimited by default. Simply moving the work into a queue and letting it run for a minute would satisfy the requirement only on paper, so the limit is enforced explicitly.

### 4. No losses, no duplicates

Rows are inserted in batches of 1000. The insert and the cursor update (`imports.cursor_row`) happen in a single transaction, so the cursor always matches what is actually in the database. If the worker dies mid-step, the next step resumes from the last committed batch.

A unique key on `(import_id, source_row)` is a second line of defence: the database itself refuses to store the same file row twice.

### 5. Progress

The page polls `GET /imports` once a second while there are unfinished imports and shows the status, a progress bar, the number of rows written, the number of rows with issues and the elapsed time. The import does not depend on the browser — the tab can be closed.

### Timing on the supplied file

| Operation | Time |
|-----------|------|
| Counting rows (for the progress bar) | 0.2 s |
| Loading the shared-strings table | 0.25 s |
| Reading and converting all rows, without the database | 5.4 s |
| Full import | about 23 s (two steps: 20 s + 3 s) |

Most of the time is spent on MySQL inserts, not on parsing.

## Data handling

The assignment requires all rows to be written, so no row is ever dropped.

- **Duplicates are kept.** 205 `external_id` values occur more than once in the file. All such rows are stored, so `leads` holds exactly 100,000 rows with 99,795 distinct `external_id` values.
- **Invalid values are kept and flagged.** The value is stored as is, and the `leads.issues` JSON column records what is wrong, for example `{"phone": "invalid"}`. The supplied file yields 1627 such rows: 813 with an invalid phone, 822 with an invalid email, 8 with both.
- **Phones** are normalised to `+380XXXXXXXXX`. For cells where the formula evaluated to an error (`#ERROR!`), the original formula text is used — that is what the person actually typed.
- **Dates** are stored in Excel as serial numbers; they are converted to `DATETIME` without a time-zone shift.
- **Empty cells** become `NULL`.
- **Columns are located by header name**, not by position. If any of the 15 expected columns is missing, the import fails with a message listing them.

## Database structure

Migrations are in `database/migrations`; a full SQL dump of the structure is in `dump.sql` in the project root (a copy is kept in `database/structure.sql`).

- `imports` — uploaded files: status, row counts, cursor, number of rows with issues, error text.
- `leads` — the leads: the 15 columns of the file plus `import_id`, `source_row` (the row number in the sheet) and `issues`.

`external_id` is indexed but not unique. Lookup values (city, source, product, manager) are stored as plain strings.

## Project structure

```
app/
├── Enums/                  ImportStatus, LeadStatus
├── Http/
│   ├── Controllers/        ImportController
│   ├── Requests/           StoreChunkRequest, StoreImportRequest
│   └── Resources/          ImportResource
├── Jobs/                   ImportLeadsJob
├── Models/                 Import, Lead
└── Services/
    ├── Import/             ChunkedUploadService, LeadImporterService, LeadRowMapperService
    └── Xlsx/               XlsxSheetReaderService
config/import.php           time budget, batch size, size limits
database/migrations/        imports, leads
database/structure.sql      SQL dump of the structure
docker/                     entrypoint.sh, nginx.conf
resources/js/               api.js, components/ImportPage.vue
resources/views/            imports.blade.php
tests/                      Unit, Feature, Support/XlsxFixture
dump.sql                    SQL dump of the structure
compose.yaml, Dockerfile
```

| Class | Responsibility |
|-------|----------------|
| `ImportController` | Thin HTTP layer: the page, chunk upload, import creation, progress. |
| `ChunkedUploadService` | Stores chunks, assembles the file, validates its size and format. |
| `ImportLeadsJob` | One import step in the queue; re-queues itself until the file is done. |
| `LeadImporterService` | Runs one step: read from the cursor, batch, insert, advance the cursor. |
| `XlsxSheetReaderService` | Streaming xlsx sheet reader. Knows nothing about leads. |
| `LeadRowMapperService` | Turns sheet cells into a `leads` row plus a list of issues. Knows nothing about xlsx or the database. |

## HTTP API

| Method | Path              | Purpose |
|--------|-------------------|---------|
| GET    | `/`               | The import page. |
| POST   | `/imports/chunks` | Accepts one chunk: `upload_id`, `index`, `chunk`. Returns 204. |
| POST   | `/imports`        | Finishes the upload: `upload_id`, `name`, `chunks`. Returns 201 with the import. |
| GET    | `/imports`        | The 10 most recent imports with progress. |
| GET    | `/imports/{id}`   | A single import. |

## Configuration

| Variable                | Default | Meaning |
|-------------------------|---------|---------|
| `APP_PORT`              | 8080    | Host port of the application. |
| `IMPORT_TIME_BUDGET`    | 20      | Seconds one import step may run. |
| `IMPORT_BATCH_SIZE`     | 1000    | Rows per `INSERT`. |
| `IMPORT_MAX_FILE_SIZE`  | 100 MB  | Maximum size of the assembled file, in bytes. |
| `IMPORT_MAX_CHUNK_SIZE` | 1024    | Maximum size of one chunk, in KB. |

## Known limitations

- **File size.** Every step except the first has to skip through the sheet up to the cursor. That costs 2.2 s for 90,000 rows and grows linearly. On files of several hundred thousand rows the skip alone would stop fitting into a step; the exact boundary has not been measured. Larger files would need a different resume strategy, such as an intermediate file addressed by byte offset.
- **Only the first sheet** of the workbook is read.
- **No upload retries.** A failed chunk request aborts the upload, and a request rate limit in front of the application would break it.
- **Abandoned uploads** leave their chunks on disk; nothing cleans them up.
- **Importing the same file twice** creates another 100,000 rows.
- **No authentication** and no page for browsing the leads themselves.
