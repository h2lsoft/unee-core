-- unee core initial data, re-runnable (each row is inserted only when missing)

-- frontend languages: list of [code, label], the first one is the default
INSERT INTO xcore_globals (category, name, type, description, value, created_at, created_by)
SELECT 'frontend', 'frontend.langs', 'json-array', 'frontend languages [code, label], the first one is the default', '[["en","English"]]', NOW(), 'unee-migrate'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM xcore_globals WHERE name = 'frontend.langs' AND deleted = 'no');
