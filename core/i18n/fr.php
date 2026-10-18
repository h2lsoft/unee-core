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

	// grid and form
	'Active' => 'Actif',
	'Label' => 'Libellé',
	'Url' => 'Url',
	'Code' => 'Code',
	'Users' => 'Utilisateurs',
	'Priority' => 'Priorité',
	'Done' => 'Terminé',
	'`[FIELD]` holds an invalid word' => '`[FIELD]` contient un mot invalide',
	'`[FIELD]` must be an url (https://...) or a path (/...)' => '`[FIELD]` doit être une url (https://...) ou un chemin (/...)',

	// module group
	'Groups' => 'Groupes',
	'group' => 'groupe',
	'Rights' => 'Droits',
	'Backend access' => 'Accès backend',
	'Frontend access' => 'Accès frontend',
	'lowest priorities are the most important' => 'les priorités les plus basses sont les plus importantes',
	'the super admin group keeps its backend access' => 'le groupe super admin garde son accès au backend',
	'you cannot remove the backend access of your own group' => 'vous ne pouvez pas retirer l\'accès backend de votre propre groupe',
	'this group is used by the application, it cannot be deleted' => 'ce groupe est utilisé par l\'application, il ne peut pas être supprimé',
	'you cannot delete your own group' => 'vous ne pouvez pas supprimer votre propre groupe',
	'this group holds [COUNT] users, move or delete them first' => 'ce groupe contient [COUNT] utilisateurs, déplacez-les ou supprimez-les d\'abord',

	// module plugin
	'plugin' => 'plugin',
	'Route' => 'Route',
	'Route / url' => 'Route / url',
	'Actions' => 'Actions',
	'Versioning' => 'Versioning',
	'Visible' => 'Visible',
	'normal' => 'normal',
	'url' => 'url',
	'folder of the module, ex: mail-template' => 'dossier du module, ex : mail-template',
	'rights of the module, given to the groups in the module right' => 'droits du module, donnés aux groupes dans le module Droits',
	'keeps a copy of each version of the records' => 'garde une copie de chaque version des enregistrements',
	'shown in the menu' => 'affiché dans le menu',
	'`[FIELD]` must hold lowercase letters, digits and dashes, ex: mail-template' => '`[FIELD]` doit contenir des minuscules, des chiffres et des tirets, ex : mail-template',
	'the plugin module stays active, it manages the others' => 'le module plugin reste actif, il gère les autres',

	// module list
	'Lists' => 'Listes',
	'value' => 'valeur',
	'value stored in the records' => 'valeur enregistrée dans les fiches',
	'text shown, translated' => 'texte affiché, traduit',
	'`[FIELD]` is already used in this category' => '`[FIELD]` est déjà utilisé dans cette catégorie',
	'grey' => 'gris',
	'orange' => 'orange',
	'red' => 'rouge',
	'green' => 'vert',
	'blue' => 'bleu',
	'purple' => 'violet',
	'yellow' => 'jaune',
	'cyan' => 'cyan',

	// module url redirect
	'Url redirects' => 'Redirections d\'url',
	'rule' => 'règle',
	'Rule' => 'Règle',
	'Search rule, url...' => 'Rechercher une règle, une url...',
	'php regex tested on the full url, ex: #^https?://example\.com/blog/#' => 'regex php testée sur l\'url complète, ex : #^https?://example\.com/blog/#',
	'url or path of the redirection, ex: /new/' => 'url ou chemin de la redirection, ex : /new/',
	'`[FIELD]` is not a valid regex: [ERROR]' => '`[FIELD]` n\'est pas une regex valide : [ERROR]',

];
