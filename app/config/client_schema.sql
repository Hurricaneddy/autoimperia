CREATE TABLE IF NOT EXISTS client_accounts (
    client_id INTEGER PRIMARY KEY REFERENCES clients(client_id) ON DELETE CASCADE,
    email TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS appointment_odometer (
    appointment_id INTEGER PRIMARY KEY REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    kilometers INTEGER NOT NULL CHECK (kilometers BETWEEN 1 AND 2000000)
);
CREATE INDEX IF NOT EXISTS appointments_client_visit ON appointments(client_id, visit_date DESC, appointment_id DESC);
CREATE INDEX IF NOT EXISTS appointment_services_by_visit ON appointment_services(appointment_id);
CREATE INDEX IF NOT EXISTS vehicles_by_client ON vehicles(client_id);
PRAGMA user_version = 1;
