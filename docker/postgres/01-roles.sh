#!/bin/sh
# Crea erp_owner (dueño del esquema) y erp_app (app, sujeta a RLS) la primera vez.
set -e
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres \
    -v owner_pass="'${ERP_OWNER_PASSWORD}'" -v app_pass="'${ERP_APP_PASSWORD}'" \
    -f /erp/roles.sql
