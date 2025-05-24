<?php

namespace LaravelEloquentMySQLi;


/**
 * \mysqli wrapper for compatible PDO
 * @author volodymyr
 *
 */
class mysqli extends \mysqli {
	
	
	public function quote(string $string ): string {
		return $this->real_escape_string($string);
	}
}