<?php
use MediaWiki\User\User;

$IP = getenv('MW_INSTALL_PATH');
if ($IP === false)
	$IP = dirname(__FILE__) . '/../../..';

require_once("$IP/maintenance/Maintenance.php");

class ResetAchievementCounter extends Maintenance
{
	function __construct()
	{
		parent::__construct();
		$this->mDescription = "Initialize specific Achievement Counter(s) in DB.";
		$this->setBatchSize(1000);
		$this->addOption('i', 'interval', true, true);
		$this->addOption('nohook', 'interval', false, false);
	}

	private function log($text = '')
	{
		$hand = fopen(__DIR__ . '/log/' . date('Y-m') . '.log', 'a+');
		if (!$hand)
			return false;
		if (is_array($text))
			$text = implode("\t", $text);
		fwrite($hand, date('Y-m-d H:i:s') . "\t" . $text . "\n");
		fclose($hand);
		return true;
	}
	function execute()
	{
		$interval = trim($this->getOption('i', false));
		$yeshook = !$this->getOption('nohook', false);

		if ($interval) {
			$interval = strtolower($interval);
			$list = Achiev\AchievementHandler::AchievementsFromReset($interval);

			$counterlist = [];
			$achievlist = [];
			foreach ($list as $achiev) {
				if (!$achiev) {
					continue;
				}
				$this->output($achiev->getID() . "\n");
				if ($achiev->isStatic()) {
					continue;
				}
				if (!$achiev->isActive()) {
					continue;
				}
				$removablelist[] = $achiev->isRemovable();
				$counterlist[] = $achiev->getCounter();
			}

			if (count($counterlist) > 0 || MediaWikiServices::getInstance()->getHookContainer()->isRegistered('Achievement::' . $interval)) {
				$dbw = $this->getDB(DB_PRIMARY);
				$i = 0;
				$maxUserId = 0;
				$userQuery = User::getQueryInfo();
				do {
					$res = $dbw->select(
						$userQuery['tables'],
						$userQuery['fields'],
						['user_id > ' . $maxUserId],
						__METHOD__,
						[
							'ORDER BY' => 'user_id',
							'LIMIT' => $this->mBatchSize,
						],
						$userQuery['joins']
					);

					foreach ($res as $row) {
						$user = User::newFromRow($row);
						if ($yeshook)
							MediaWikiServices::getInstance()->getHookContainer()->run('Achievement::' . $interval, [$user, $this]);
						foreach ($counterlist as $n => $c) {
							if (!$removablelist[$n])
								$c->updateAchiev($user, false);
							$c->resetCount($user, false);
						}
						Achiev\AchievementHandler::clearUserCache($user, 'refresh');
						++$i;
					}
					$maxUserId = $row->user_id;
				} while ($res->numRows());

				$this->log($interval);
			}
		}
		$this->output("end\n");
	}
}

$maintClass = 'ResetAchievementCounter';
require_once(RUN_MAINTENANCE_IF_MAIN);
