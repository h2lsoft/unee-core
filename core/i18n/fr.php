<?php
/**
 * french translations of the core, key = english text (\Core\I18n, validator messages)
 * an instance adds or overrides keys in instances/<dir>/i18n/fr.php
 */

return [

	// api
	'validation failed' => 'Certains champs sont invalides',
	'invalid credentials' => 'Identifiants invalides',
	'authentication required' => 'Authentification requise',
	'session expired' => 'Session expirée',
	'account disabled' => 'Compte désactivé',
	'password changed, sign in again' => 'Mot de passe modifié, reconnectez-vous',
	'too many failed attempts, retry later' => 'Trop de tentatives échouées, réessayez plus tard',
	'too many requests, retry later' => 'Trop de demandes, réessayez plus tard',
	'If an account exists for this email address, a recovery link has been sent.' => 'Si un compte existe pour cette adresse e-mail, un lien de récupération a été envoyé.',
	'this link is invalid or has expired, ask for a new one' => 'Ce lien est invalide ou a expiré, demandez-en un nouveau',
	'Your password has been changed, you can sign in.' => 'Votre mot de passe a été modifié, vous pouvez vous connecter.',
	'access denied' => 'Accès refusé',
	'record not found' => 'Enregistrement introuvable',
	'select between 1 and 500 records' => 'Sélectionnez entre 1 et 500 enregistrements',
	'column not editable' => 'Colonne non modifiable',
	'deletion refused' => 'Suppression refusée',
	'missing header X-Requested-With: XMLHttpRequest' => 'En-tête X-Requested-With: XMLHttpRequest manquant',
	'invalid json body' => 'Corps JSON invalide',
	'internal server error' => 'Erreur interne du serveur',

	// validator (short messages, shown under the fields)
	'required' => 'obligatoire',
	'invalid value' => 'valeur invalide',
	'invalid email address' => 'adresse e-mail invalide',
	'invalid number' => 'nombre invalide',
	'invalid date' => 'date invalide',
	'already used' => 'déjà utilisé',
	'[LENGTH] characters maximum' => '[LENGTH] caractères maximum',
	'[MIN] to [MAX] characters' => '[MIN] à [MAX] caractères',
	'passwords do not match' => 'les mots de passe ne correspondent pas',

	// grid and form
	'Search...' => 'Rechercher...',
	'Yes' => 'Oui',
	'No' => 'Non',
	'ID' => 'ID',
	'Name' => 'Nom',
	'Type' => 'Type',
	'Value' => 'Valeur',
	'Category' => 'Catégorie',
	'Description' => 'Description',

	// menu
	'Administration' => 'Administration',

	// module globals
	'Globals' => 'Variables globales',
	'global' => 'variable globale',
	'Search name...' => 'Rechercher un nom...',
	'ex: backend.logo' => 'ex : backend.logo',
	'existing category or a new one' => 'catégorie existante ou nouvelle',
	'letters, digits, dashes and underscores only' => 'lettres, chiffres, tirets et underscores uniquement',
	'url (https://...) or path starting with /' => 'url (https://...) ou chemin commençant par /',
	'leave empty to keep the current value' => 'laisser vide pour conserver la valeur actuelle',
	'letters, digits, dots, dashes and underscores only' => 'lettres, chiffres, points, tirets et underscores uniquement',
	'true, false, 1 or 0' => 'true, false, 1 ou 0',
	'invalid json array' => 'tableau JSON invalide',
	'invalid json object' => 'objet JSON invalide',

	// saved filters
	'add at least one condition' => 'ajoutez au moins une condition',

];
