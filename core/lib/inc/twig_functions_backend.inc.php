<?php

$function = new \Twig\TwigFunction('get_user_avatar_url', function(int|string $user_id=0, string $initials=''):string{
	return \Model\User::getAvatarBadgeUrl($user_id, $initials);
}, ['is_safe' => ['html']]);
$this->twig->addFunction($function);
