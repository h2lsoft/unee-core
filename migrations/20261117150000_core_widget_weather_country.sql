-- widget Weather: setting country (France by the IGN geocoder, the other countries by Nominatim of OpenStreetMap)
-- not changed when an admin changed the widget

UPDATE `xcore_widget` SET `config_fields` = '[{"name": "city", "label": "City", "type": "text", "default": "Paris"}, {"name": "country", "label": "Country", "type": "select", "default": "FR", "options": {"FR": "France", "BE": "Belgium", "CH": "Switzerland", "LU": "Luxembourg", "MC": "Monaco", "DE": "Germany", "ES": "Spain", "IT": "Italy", "PT": "Portugal", "NL": "Netherlands", "GB": "United Kingdom", "IE": "Ireland", "AT": "Austria", "PL": "Poland", "GR": "Greece", "CA": "Canada", "US": "United States", "MX": "Mexico", "BR": "Brazil", "MA": "Morocco", "DZ": "Algeria", "TN": "Tunisia", "SN": "Senegal", "CI": "Ivory Coast", "IL": "Israel", "AE": "United Arab Emirates", "TR": "Turkey", "JP": "Japan", "CN": "China", "AU": "Australia"}}]', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `deleted` = 'no' AND `created_by` = 'unee-migrate' AND (`updated_by` IS NULL OR `updated_by` = 'unee-migrate')
AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetWeather');

-- @down

UPDATE `xcore_widget` SET `config_fields` = '[{"name": "city", "label": "City", "type": "text", "default": "Paris"}]', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `updated_by` = 'unee-migrate' AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetWeather');
