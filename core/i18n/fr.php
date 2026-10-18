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
	'choose at least one field' => 'Choisissez au moins un champ',
	'record #[ID]: [MESSAGE]' => 'enregistrement #[ID] : [MESSAGE]',
	'action not found' => 'Action introuvable',
	'column not editable' => 'Colonne non modifiable',
	'deletion refused' => 'Suppression refusée',
	'invalid value' => 'Valeur invalide',
	'missing header X-Requested-With: XMLHttpRequest' => 'En-tête X-Requested-With: XMLHttpRequest manquant',
	'invalid json body' => 'Corps JSON invalide',
	'internal server error' => 'Erreur interne du serveur',

	// validator: messages missing in h2lsoft/validator locale, [FIELD] = label of the input
	'`[FIELD]` is already used' => '`[FIELD]` est déjà utilisé',
	'`[FIELD]` must be a valid JSON array' => '`[FIELD]` doit être un tableau JSON valide',
	'`[FIELD]` must be a valid JSON object' => '`[FIELD]` doit être un objet JSON valide',
	'`[FIELD]` must contain [MIN] to [MAX] characters' => '`[FIELD]` doit contenir [MIN] à [MAX] caractères',
	'`[FIELD]` must be a valid path' => '`[FIELD]` doit être un chemin valide',
	'`[FIELD]` must be an icon name, ex: settings' => '`[FIELD]` doit être un nom d\'icône, ex : settings',
	'`[FIELD]` must be a color #rrggbb' => '`[FIELD]` doit être une couleur #rrggbb',
	'`[FIELD]` must contain letters, digits, dots, dashes and underscores only' => '`[FIELD]` doit contenir uniquement des lettres, chiffres, points, tirets et underscores',
	'`[FIELD]` must contain letters, digits, dashes and underscores only' => '`[FIELD]` doit contenir uniquement des lettres, chiffres, tirets et underscores',

	// labels of the auth inputs
	'Email address' => 'Adresse e-mail',
	'Login' => 'Identifiant',
	'Password' => 'Mot de passe',
	'New password' => 'Nouveau mot de passe',
	'Confirm password' => 'Confirmer le mot de passe',
	'Language' => 'Langue',
	'Filter name' => 'Nom du filtre',

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

	// module menu
	'Menu' => 'Menu',
	'category' => 'catégorie',
	'Icon' => 'Icône',
	'Color' => 'Couleur',
	'Plugins' => 'Plugins',
	'name of a Material Symbols icon, ex: settings' => 'nom d\'une icône Material Symbols, ex : settings',
	'this category holds [COUNT] plugins, move or delete them first' => 'cette catégorie contient [COUNT] plugins, déplacez-les ou supprimez-les d\'abord',

	// module globals
	'Globals' => 'Variables globales',
	'global' => 'variable globale',
	'Search name...' => 'Rechercher un nom...',
	'ex: backend.logo' => 'ex : backend.logo',
	'existing category or a new one' => 'catégorie existante ou nouvelle',
	'leave empty to keep the current value' => 'laisser vide pour conserver la valeur actuelle',

	// saved filters
	'add at least one condition' => 'ajoutez au moins une condition',

];
