CREATE USER IF NOT EXISTS 'crucible'@'%' IDENTIFIED BY 'crucible';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX ON crucible_target.* TO 'crucible'@'%';
CREATE TABLE crucible_target.native_client_fixture (
    id integer PRIMARY KEY,
    label varchar(32) NOT NULL
);
INSERT INTO crucible_target.native_client_fixture (id, label) VALUES
    (1, 'alpha'),
    (2, 'beta');
FLUSH PRIVILEGES;
