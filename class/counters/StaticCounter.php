<?php

namespace Achiev;

use User;

class StaticCounter extends Counter
{

	public function initUserCount(User $user, $value = 0)
	{
		if ($this->isBlocked($user))
			return;
		return $this->config['init'] + $value;
	}

	public function updateHook($opt, $user, &$count = null)
	{
		if ($this->isBlocked($user))
			return;
		$count = is_null($count) ? 1 : intval($count);

		if ($opt[1] === 'Achievement::setStaticCountTo') {
			$this->updateUserCountDB($user, $count);
		} else {
			$this->addUserCountDB($user, $count);
		}
		$this->updateAchievSafe($user);
	}

	public function defaultConfig()
	{
		return [
			'init' => 0,
		];
	}
}