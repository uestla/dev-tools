<?php

/**
 * Class names updater.
 *
 * This file is part of the Nette Framework (http://nette.org)
 */

if (@!include __DIR__ . '/../vendor/autoload.php') {
	die('Install packages using `composer update`');
}

use Nette\Utils\Tokenizer;


$options = getopt('d:f');

if (!$options) { ?>

Updater updates Nette Framework 2.0 to 2.1. Works ONLY with PHP 5.3 package.

Usage: php class-updater.php [options]

Options:
	-d <path>  folder to scan (optional)
	-f         fixes files

<?php
}



class ClassUpdater extends Nette\Object
{
	public $readOnly = FALSE;

	/** @var array */
	private $uses;

	/** @var array */
	private $newUses;

	/** @var string */
	private $namespace;

	/** @var */
	private $fileName;

	/** @var array */
	private $found;

	/** @var array */
	private $replaces = array(
		'Nette\Config\Adapters\IniAdapter' => 'Nette\DI\Config\Adapters\IniAdapter',
		'Nette\Config\Adapters\NeonAdapter' => 'Nette\DI\Config\Adapters\NeonAdapter',
		'Nette\Config\Adapters\PhpAdapter' => 'Nette\DI\Config\Adapters\PhpAdapter',
		'Nette\Config\Compiler' => 'Nette\DI\Compiler',
		'Nette\Config\CompilerExtension' => 'Nette\DI\CompilerExtension',
		'Nette\Config\Configurator' => 'Nette\Configurator',
		'Nette\Config\Helpers' => 'Nette\DI\Config\Helpers',
		'Nette\Config\IAdapter' => 'Nette\DI\Config\IAdapter',
		'Nette\Config\Loader' => 'Nette\DI\Config\Loader',
		'Nette\Extensions\ConstantsExtension' => 'Nette\DI\Extensions\ConstantsExtension',
		'Nette\Extensions\NetteExtension' => 'Nette\DI\Extensions\NetteExtension',
		'Nette\Extensions\PhpExtension' => 'Nette\DI\Extensions\PhpExtension',
		'Nette\Utils\PhpGenerator\ClassType' => 'Nette\PhpGenerator\ClassType',
		'Nette\Utils\PhpGenerator\Helpers' => 'Nette\PhpGenerator\Helpers',
		'Nette\Utils\PhpGenerator\Method' => 'Nette\PhpGenerator\Method',
		'Nette\Utils\PhpGenerator\Parameter' => 'Nette\PhpGenerator\Parameter',
		'Nette\Utils\PhpGenerator\PhpLiteral' => 'Nette\PhpGenerator\PhpLiteral',
		'Nette\Utils\PhpGenerator\Property' => 'Nette\PhpGenerator\Property',
	);

	/** @var array */
	private $deprecated = array(
		'Nette\Loaders\AutoLoader' => FALSE,
		'Nette\Diagnostics\Debugger::$consoleMode' => FALSE,
		'Nette\Diagnostics\Debugger::$consoleColors' => FALSE,
		'Nette\Diagnostics\Debugger::$logger' => FALSE,
		'Nette\Diagnostics\Debugger::$fireLogger' => FALSE,
		'Nette\Diagnostics\Debugger::$blueScreen' => FALSE,
		'Nette\Diagnostics\Debugger::$bar' => FALSE,
		'Nette\Diagnostics\Debugger::toStringException' => FALSE,
		'Nette\Diagnostics\Debugger::tryError' => FALSE,
		'Nette\Diagnostics\Debugger::catchError' => FALSE,
		'Nette\Diagnostics\Helpers::htmlDump' => FALSE,
		'Nette\Diagnostics\Helpers::clickableDump' => FALSE,
		'Nette\Diagnostics\Helpers::textDump' => FALSE,
		'Nette\IFreezable' => FALSE,
		'Nette\Freezable' => FALSE,
		'Nette\Http\ISessionStorage' => FALSE,
		'Nette\Mail\Message::$defaultMailer' => FALSE,
		'Nette\Configurator::DEVELOPMENT' => FALSE,
		'Nette\Configurator::PRODUCTION' => FALSE,
		'Nette\Config\Configurator::DEVELOPMENT' => FALSE,
		'Nette\Config\Configurator::PRODUCTION' => FALSE,
	);



	public function run($folder)
	{
		set_time_limit(0);

		if ($this->readOnly) {
			echo "Running in read-only mode\n";
		}

		echo "Scanning folder $folder...\n";

		foreach ($this->replaces as $old => $new) {
			if (preg_match('#^(?!I[A-Z])[A-Z][^\\\\]+\z#', $old)) {
				$this->replaces["N$old"] = $new;
			}
		}
		$this->replaces = array_change_key_case($this->replaces);
		$this->deprecated = array_change_key_case($this->deprecated);


		$counter = 0;
		foreach (Nette\Utils\Finder::findFiles('*.php')->from($folder)
			->exclude(array('.*', '*.tmp', 'tmp', 'temp', 'log')) as $file)
		{
			echo str_pad(str_repeat('.', $counter++ % 40), 40), "\x0D";

			$this->fileName = ltrim(str_replace($folder, '', $file), '/\\');

			$orig = file_get_contents($file);
			$new = $this->processFile($orig);
			if ($new !== $orig) {
				$this->report($this->readOnly ? 'FOUND' : 'FIX', implode(', ', array_keys($this->found)));
				if (!$this->readOnly) {
					file_put_contents($file, $new);
				}
			}
		}

		echo "\nDone.";
	}



	public function report($level, $message = '')
	{
		echo "[$level] $this->fileName   $message\n";
	}



	public function processFile($input)
	{
		$this->namespace = '';
		$this->uses = $this->newUses = $this->found = array();
		$parser = new PhpParser($input);

		while ($token = $parser->nextToken()) {

			if ($parser->isCurrent(T_NAMESPACE)) {
				$this->namespace = (string) $parser->joinAll(T_STRING, T_NS_SEPARATOR);
				$this->uses = $this->newUses = array();

			} elseif ($parser->isCurrent(T_USE)) {
				if ($parser->isNext('(')) { // closure?
					continue;
				}
				do {
					$parser->nextAll(T_WHITESPACE, T_COMMENT);

					$pos = $parser->position + 1;
					$class = $newClass = ltrim($parser->joinAll(T_STRING, T_NS_SEPARATOR), '\\');
					if (isset($this->replaces[strtolower($class)])) {
						$parser->replace($newClass = $this->replaces[strtolower($class)], $pos);
					}

					if ($parser->nextToken(T_AS)) {
						$as = $newAs = $parser->nextValue(T_STRING);
					} else {
						$as = substr($class, strrpos("\\$class", '\\'));
						$newAs = substr($newClass, strrpos("\\$newClass", '\\'));
					}
					$this->uses[strtolower($as)] = $class;
					while (isset($this->newUses[strtolower($newAs)])) {
						$newAs .= '_';
						$parser->replace("$class as $newAs", $pos);
					}
					$this->newUses[strtolower($newAs)] = array($newClass, $newAs);

				} while ($parser->nextToken(','));

			} elseif ($parser->isCurrent(T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_NEW)) {
				do {
					$parser->nextAll(T_WHITESPACE, T_COMMENT);
					$pos = $parser->position + 1;
					if ($class = $parser->joinAll(T_STRING, T_NS_SEPARATOR)) {
						$parser->replace($this->renameClass($class), $pos);
					}
				} while ($class && $parser->nextToken(','));

			} elseif ($parser->isCurrent(T_STRING, T_NS_SEPARATOR)) {
				$pos = $parser->position;
				$identifier = $token[Tokenizer::VALUE] . $parser->joinAll(T_STRING, T_NS_SEPARATOR);
				if ($parser->nextToken(T_DOUBLE_COLON)) { // Class::
					$member = $parser->nextValue(T_STRING, T_VARIABLE);
					$parser->replace($this->renameClass($identifier, $member), $pos);

				} elseif ($parser->isNext(T_VARIABLE)) { // typehint
					$parser->replace($this->renameClass($identifier), $pos);
				}

			} elseif ($parser->isCurrent(T_DOC_COMMENT, T_COMMENT)) {
				// @var Class or \Class or Nm\Class or Class:: (preserves CLASS)
				$that = $this;
				$parser->replace(preg_replace_callback('#((?:@var(?:\s+array of)?|returns?|param|throws|@link|property[\w-]*)\s+)([\w\\\\|]+)#', function($m) use ($that) {
					$parts = array();
					foreach (explode('|', $m[2]) as $part) {
						$parts[] = preg_match('#^\\\\?[A-Z].*[a-z]#', $part) ? $that->renameClass($part) : $part;
					}
					return $m[1] . implode('|', $parts);
				}, $token[Tokenizer::VALUE]));

			} elseif ($parser->isCurrent(T_CONSTANT_ENCAPSED_STRING)) {
				if (preg_match('#(^.\\\\?)(Nette(?:\\\\{1,2}[A-Z]\w+)*)(:.*|.\z)#', $token[Tokenizer::VALUE], $m)) { // 'Nette\Object'
					$class = str_replace('\\\\', '\\', $m[2], $double);
					if (isset($that->replaces[strtolower($class)])) {
						$class = $that->replaces[strtolower($class)];
						$parser->replace($m[1] . str_replace('\\', $double ? '\\\\' : '\\', $class) . $m[3]);
					}
				}

			} elseif ($parser->isCurrent(T_OBJECT_OPERATOR)) {
				$pos = $parser->position;
				$member = $parser->nextValue(T_STRING);
				$s = strtolower('->' . $member . $parser->nextValue('('));
				if (isset($this->deprecated[$s])) {
					$this->report('WARNING', "Found a possible deprecated member $member on line {$parser->tokens[$pos]['line']}"
						. ($this->deprecated[$s] ? "; use {$this->deprecated[$s]} instead" : ''));
				}
			}
		}

		$parser->reset();
		return $parser->joinAll();
	}



	/**
	 * Renames class.
	 * @param  string class
	 * @return string new class
	 */
	function renameClass($class, $member = NULL)
	{
		if ($class === 'parent' || $class === 'self' || !$class) {
			return $class . ($member ? "::$member" : '');
		}

		$class = $this->resolveClass($class);

		if (isset($this->deprecated[strtolower("$class::$member")]) || isset($this->deprecated[strtolower("::$member")])) {
			$this->report('ERROR', "Found deprecated '$class::$member'");

		} elseif (isset($this->deprecated[strtolower($class)])) {
			$this->report('ERROR', "Found deprecated class '$class'");

		} elseif (isset($this->replaces[strtolower("$class::$member")])) {
			list($class, $member) = explode('::', $this->replaces[strtolower("$class::$member")]);

		} elseif (isset($this->replaces[strtolower($class)])) {
			$newClass = $this->replaces[strtolower($class)];
			if (strpos($class, '\\') !== FALSE || strpos($newClass, '\\') === FALSE) {
				$this->found["$class -> $newClass"] = TRUE;
				$class = $newClass;
			}
		}

		return $this->applyUse($class) . ($member ? "::$member" : '');
	}



	/**
	 * Apply use statements.
	 * @param  string
	 * @return string
	 */
	function applyUse($class)
	{
		$best = strncasecmp($class, "$this->namespace\\", strlen("$this->namespace\\")) === 0
			? substr($class, strlen($this->namespace) + 1)
			: ($this->namespace ? '\\' : '') . $class;

		foreach ($this->newUses as $item) {
			list($use, $as) = $item;
			if (strncasecmp("$class\\", "$use\\", strlen("$use\\")) === 0) {
				$new = substr_replace($class, $as, 0, strlen($use));
				if (strlen($new) <= strlen($best)) {
					$best = $new;
				}
			}
		}

		return $best;
	}



	/**
	 * Resolve use statements.
	 * @param  string
	 * @return string|NULL
	 */
	function resolveClass($class)
	{
		$segment = strtolower(substr($class, 0, strpos("$class\\", '\\')));
		if ($segment === '') {
			$full = $class;
		} elseif (isset($this->uses[$segment])) {
			$full = $this->uses[$segment] . substr($class, strlen($segment));
		} else {
			$full = $this->namespace . '\\' . $class;
		}
		return ltrim($full, '\\');
	}

}



/**
 * Simple tokenizer for PHP.
 */
class PhpParser extends Nette\Utils\TokenIterator
{

	function __construct($code)
	{
		$this->ignored = array(T_COMMENT, T_DOC_COMMENT, T_WHITESPACE);
		foreach (token_get_all($code) as $token) {
			$this->tokens[] = array(
				Tokenizer::VALUE => is_array($token) ? $token[1] : $token,
				Tokenizer::TYPE => is_array($token) ? $token[0] : NULL,
			);
		}
	}



	function replace($s, $start = NULL)
	{
		for ($i = ($start === NULL ? $this->position : $start); $i < $this->position; $i++) {
			$this->tokens[$i] = array(Tokenizer::VALUE => '');
		}
		$this->tokens[$this->position] = array(Tokenizer::VALUE => $s);
	}

}



$updater = new ClassUpdater;
$updater->readOnly = !isset($options['f']);
$updater->run(isset($options['d']) ? $options['d'] : getcwd());
