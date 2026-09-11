CREATE ROLE crucible LOGIN PASSWORD 'crucible' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT CONNECT, TEMPORARY ON DATABASE crucible_target TO crucible;
\connect crucible_target
GRANT USAGE, CREATE ON SCHEMA public TO crucible;
CREATE TABLE native_client_fixture (
    id integer PRIMARY KEY,
    label text NOT NULL
);
INSERT INTO native_client_fixture (id, label) VALUES
    (1, 'alpha'),
    (2, 'beta');
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE native_client_fixture TO crucible;
