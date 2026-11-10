-- second factor settings moved from the globals to the config of the instance (security settings): auth/security/<application>/two_factor/method, allowed_methods, trust_days, auth/security/two_factor/code/*
-- the globals created by the first reads are removed (soft delete), an instance that changed them sets the same values in its config

UPDATE `xcore_globals` SET `deleted` = 'yes', `deleted_at` = NOW(), `deleted_by` = 'unee-migrate' WHERE `name` IN ('auth.backend.two_factor.method', 'auth.frontend.two_factor.method', 'auth.backend.two_factor.trust_days', 'auth.frontend.two_factor.trust_days', 'auth.two_factor.code.ttl', 'auth.two_factor.code.max_attempts', 'auth.two_factor.code.resend_delay', 'auth.two_factor.code.max_resends') AND `deleted` = 'no';

-- @down

UPDATE `xcore_globals` SET `deleted` = 'no', `deleted_at` = NULL, `deleted_by` = NULL WHERE `name` IN ('auth.backend.two_factor.method', 'auth.frontend.two_factor.method', 'auth.backend.two_factor.trust_days', 'auth.frontend.two_factor.trust_days', 'auth.two_factor.code.ttl', 'auth.two_factor.code.max_attempts', 'auth.two_factor.code.resend_delay', 'auth.two_factor.code.max_resends') AND `deleted` = 'yes' AND `deleted_by` = 'unee-migrate';
