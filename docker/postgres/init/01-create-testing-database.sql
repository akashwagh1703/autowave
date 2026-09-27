-- Runs only on first container initialisation (empty data volume).
-- The test suite runs against PostgreSQL, never SQLite, so behaviour matches production.
CREATE DATABASE autowave_testing;
