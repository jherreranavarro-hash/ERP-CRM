-- Roles de PostgreSQL del sistema. Correr una vez por servidor como superusuario:
--   psql -v owner_pass="'...'" -v app_pass="'...'" -f database/setup/roles.sql
-- erp_owner es dueño del esquema y corre migraciones; erp_app es el rol de la
-- aplicación: no es dueño, así que Row-Level Security siempre se le aplica.
CREATE ROLE erp_owner LOGIN PASSWORD :owner_pass;
CREATE ROLE erp_app LOGIN PASSWORD :app_pass NOSUPERUSER NOBYPASSRLS;
CREATE DATABASE erp OWNER erp_owner;
CREATE DATABASE erp_test OWNER erp_owner;
REVOKE ALL ON DATABASE erp, erp_test FROM PUBLIC;
GRANT CONNECT ON DATABASE erp, erp_test TO erp_app;
\c erp
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO erp_owner;
\c erp_test
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO erp_owner;
