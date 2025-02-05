<?php
declare(strict_types=1);

class FreshRSS_EntryDAOPGSQL extends FreshRSS_EntryDAOSQLite {

	#[\Override]
	public static function hasNativeHex(): bool {
		return true;
	}

	#[\Override]
	public static function sqlHexDecode(string $x): string {
		return 'decode(' . $x . ", 'hex')";
	}

	#[\Override]
	public static function sqlHexEncode(string $x): string {
		return 'encode(' . $x . ", 'hex')";
	}

	#[\Override]
	public static function sqlIgnoreConflict(string $sql): string {
		return rtrim($sql, ' ;') . ' ON CONFLICT DO NOTHING';
	}

	#[\Override]
	public static function sqlRandom(): string {
		return 'RANDOM()';
	}

	#[\Override]
	protected static function sqlRegex(string $expression, string $regex, array &$values): string {
		$matches = static::regexToSql($regex);
		if (isset($matches['pattern'])) {
			$matchType = $matches['matchType'] ?? '';
			if (str_contains($matchType, 'm')) {
				// newline-sensitive matching
				$matches['pattern'] = '(?m)' . $matches['pattern'];
			}
			$values[] = $matches['pattern'];
			if (str_contains($matchType, 'i')) {
				// case-insensitive matching
				return "{$expression} ~* ?";
			} else {
				// case-sensitive matching
				return "{$expression} ~ ?";
			}
		}
		return '';
	}

	#[\Override]
	protected function registerSqlFunctions(string $sql): void {
		// Nothing to do for PostgreSQL
	}

	/** @param array{0:string,1:int,2:string} $errorInfo */
	#[\Override]
	protected function autoUpdateDb(array $errorInfo): bool {
		if (isset($errorInfo[0])) {
			if ($errorInfo[0] === FreshRSS_DatabaseDAO::ER_BAD_FIELD_ERROR || $errorInfo[0] === FreshRSS_DatabaseDAOPGSQL::UNDEFINED_COLUMN) {
				$errorLines = explode("\n", (string)$errorInfo[2], 2);	// The relevant column name is on the first line, other lines are noise
				foreach (['attributes'] as $column) {
					if (stripos($errorLines[0], $column) !== false) {
						return $this->addColumn($column);
					}
				}
			}
		}
		return false;
	}

	#[\Override]
	public function commitNewEntries(): bool {
		//TODO: Update to PostgreSQL 9.5+ syntax with ON CONFLICT DO NOTHING
		$sql = 'DO $$
DECLARE
maxrank bigint := (SELECT MAX(id) FROM `_entrytmp`);
rank bigint := (SELECT maxrank - COUNT(*) FROM `_entrytmp`);
BEGIN
	INSERT INTO `_entry`
		(id, guid, title, author, content, link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes)
		(SELECT rank + row_number() OVER(ORDER BY date, id) AS id, guid, title, author, content,
			link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes
			FROM `_entrytmp` AS etmp
			WHERE NOT EXISTS (
				SELECT 1 FROM `_entry` AS ereal
				WHERE (etmp.id = ereal.id) OR (etmp.id_feed = ereal.id_feed AND etmp.guid = ereal.guid))
			ORDER BY date, id);
	DELETE FROM `_entrytmp` WHERE id <= maxrank;
END $$;';
		$hadTransaction = $this->pdo->inTransaction();
		if (!$hadTransaction) {
			$this->pdo->beginTransaction();
		}
		$result = $this->pdo->exec($sql) !== false;
		if (!$hadTransaction) {
			$this->pdo->commit();
		}
		return $result;
	}
}
