<?php

namespace Achiev;

use \User;
use MediaWiki\MediaWikiServices;
use WikiPage;
use Title;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;

class EditCountCounter extends Counter
{

	public function initUserCount(User $user, $value = 0)
	{
		if ($this->isBlocked($user)) {
			return;
		}

		if ($this->config['init'] !== 'current') {
			return $this->config['init'] + $value;
		}

		$cond = $this->buildCond();
		if (empty($cond)) {
			return $user->getEditCount() + $value;
		}

		$dbr = wfGetDB(DB_REPLICA);

		if (isset($cond['page'])) {
			$res = $dbr->select(
				['page', 'revision'],
				'COUNT(DISTINCT page_id)',
				[
					$cond['subpage'] ? $dbr->makeList(
						['page_title' => $cond['page'], 'page_title ' . $dbr->buildLike($cond['page'] . '/', $dbr->anyString())],
							$dbr::LIST_OR
					) : $dbr->makeList(
							['page_title' => $cond['page']],
								$dbr::LIST_AND
						),
					'page_namespace' => $cond['ns'],
					'page_is_redirect' => 0,
				],
				__METHOD__,
				[],
				[
					'revision' => [
						'INNER JOIN',
						['rev_page=page_id', 'rev_actor' => $user->getActorId()]
					]
				]
			);
		} else {
			if (isset($cond['cat'])) {
				$res = $dbr->select(
					['page', 'revision', 'categorylinks'],
					'COUNT(DISTINCT page_id)',
					isset($cond['ns']) ? ['page_namespace' => $cond['ns']] : [],
					__METHOD__,
					[],
					[
						'revision' => [
							'INNER JOIN',
							['rev_page=page_id', 'rev_actor' => $user->getActorId()]
						],
						'categorylinks' => [
							'INNER JOIN',
							['cl_from=page_id', 'cl_to' => $cond['cat']]
						]
					]
				);
			} elseif (isset($cond['ns'])) {
				$res = $dbr->select(
					['page', 'revision'],
					'COUNT(DISTINCT page_id)',
					['page_namespace' => $cond['ns'], 'page_is_redirect' => 0],
					__METHOD__,
					[],
					[
						'revision' => [
							'INNER JOIN',
							['rev_page=page_id', 'rev_actor' => $user->getActorId()]
						]
					]
				);
			}
		}

		if ($res === false || !$res->numRows()) {
			$count = 0;
		}
		$row = $res->fetchRow();

		if ($row !== false) {
			$count = (int) reset($row);
		} else {
			$count = 0;
		}
		return $count + $value;
	}

	public function isBlocked($user)
	{
		return !($user instanceof User) || $user->isAnon() || $user->getBlock() || $user->isBot();
	}

	private function log($text = '')
	{
		$hand = fopen('/home/thwikicc/mediawiki/error_log', 'a+');
		if (!$hand)
			return false;
		if (is_array($text))
			$text = implode("\t", $text);
		fwrite($hand, date('Y-m-d H:i:s') . "\t" . $text . "\n");
		fclose($hand);
		return true;
	}

	public function updateHook($opt, WikiPage $wikiPage, UserIdentity $user, string $summary, int $flags, RevisionRecord $revisionRecord, EditResult $editResult)
	{ // PageSaveComplete
		if ($editResult->isNullEdit()) {
			return;
		}

		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity($user);
		if ($this->isBlocked($user)) {
			return;
		}
		$title = $wikiPage->getTitle();
		if (!$title->exists())
			return;

		$cond = $this->buildCond();
		if (empty($cond)) {
			$this->addUserCountDB($user, 1);
			$this->updateAchievSafe($user);
		} else {
			$inpage = true;
			$inns = true;
			$incat = true;
			$dbr = wfGetDB(DB_REPLICA);
			if (isset($cond['page'])) {
				if ($title->inNamespace($cond['ns']) && $title->getDBkey() === $cond['page']) {
					$inpage = true;
				} else {
					if ($cond['subpage'] && mb_substr($title->getDBkey(), 0, mb_strlen($cond['page'] . '/')) === $cond['page'] . '/') {
						$inpage = true;
					} else {
						$inpage = false;
					}
				}
			} else {
				if (isset($cond['ns'])) {
					$inns = $title->inNamespace($cond['ns']);
				}
				if (isset($cond['cat'])) {
					$cats = $wikiPage->getCategories();
					$incat = false;
					$cattext = '';
					foreach ($cats as $cat) {
						$cattext .= $cat->getText() . '|';
						if ($cat->getText() === $cond['cat']) {
							$incat = true;
							break;
						}
					}
				}
			}
			if ($inpage && $inns && $incat) {
				$contributors = $this->getContributors($wikiPage, $revisionRecord);
				$prev = false;
				$usertext = '';
				foreach ($contributors as $contributor) {
					$usertext .= $contributor->getName() . '|';
					if ($contributor->equals($user)) {
						$prev = true;
						break;
					}
				}
				if ($prev === false) {
					$this->addUserCountDB($user, 1);
					$this->updateAchievSafe($user);
				}
			}
		}
	}

	private function getContributors(WikiPage $wikiPage, RevisionRecord $revisionRecord)
	{
		$dbr = wfGetDB(DB_PRIMARY);

		$actorMigration = \ActorMigration::newMigration();
		$actorQuery = $actorMigration->getJoin('rev_user');

		$tables = array_merge(['revision'], $actorQuery['tables'], ['user']);

		$revactor_actor = $actorQuery['fields']['rev_actor'];
		$fields = [
			'user_id' => $actorQuery['fields']['rev_user'],
			'user_name' => $actorQuery['fields']['rev_user_text'],
			'actor_id' => "MIN($revactor_actor)",
			'user_real_name' => 'MIN(user_real_name)',
			'timestamp' => 'MAX(rev_timestamp)',
		];

		$conds = [
			'rev_page' => $wikiPage->getId(),
			'rev_id <> ' . (int) $revisionRecord->getId()
		];
		//'NOT(' . $actorMigration->getWhere( $dbr, 'rev_user', $user )['conds'] . ')'

		// Username hidden?
		$conds[] = "{$dbr->bitAnd('rev_deleted', RevisionRecord::DELETED_USER)} = 0";

		$jconds = [
			'user' => ['LEFT JOIN', $actorQuery['fields']['rev_user'] . ' = user_id'],
		] + $actorQuery['joins'];

		$options = [
			'GROUP BY' => [$fields['user_id'], $fields['user_name']],
			'ORDER BY' => 'timestamp DESC',
		];

		$res = $dbr->select($tables, $fields, $conds, __METHOD__, $options, $jconds);
		return new \UserArrayFromResult($res);
	}

	protected function buildCond()
	{
		$cond = [];
		if (isset($this->config['page']) && $this->config['page'] !== '') {
			$cond['page'] = $this->config['page'];
			if (isset($this->config['subpage']) && $this->config['subpage']) {
				$cond['subpage'] = true;
			} else {
				$cond['subpage'] = false;
			}
			if (isset($this->config['ns']) && intval($this->config['ns']) >= 0) {
				$cond['ns'] = intval($this->config['ns']);
			} else {
				$cond['ns'] = 0;
			}
		} else {
			if (isset($this->config['ns']) && intval($this->config['ns']) >= 0) {
				$cond['ns'] = intval($this->config['ns']);
			}
			if (isset($this->config['cat']) && $this->config['cat'] !== '') {
				$cond['cat'] = $this->config['cat'];
			}
		}
		return $cond;
	}

	public function defaultConfig()
	{
		return [
			'init' => 'current',
		];
	}
}