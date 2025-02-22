<?php declare(strict_types=1);

namespace Nadybot\Patcher;

use Composer\Installer\PackageEvent;
use Composer\Package\Package;
use Exception;

/**
 * This class is used as a callback-provider when installing or updating
 * composer packages.
 *
 * - PHP Codesniffer gets a default config to use the Nadybot styleguide.
 *   deprecation warnings.
 */
class Patcher {
	/** Callback for composer install and update events */
	public static function patch(PackageEvent $event): void {
		$vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
		$operation = $event->getOperation();
		if (method_exists($operation, 'getOperationType')) {
			$operationType = $operation->getOperationType();
		} elseif (method_exists($operation, 'getJobType')) {
			$operationType = $operation->getJobType();
		} elseif (defined(get_class($operation) . '::TYPE')) {
			$operationType = constant(get_class($operation) . '::TYPE');
		} else {
			throw new Exception('You are using an unsupported version of Composer');
		}
		if ($operationType === 'install') {
			/** @var \Composer\DependencyResolver\Operation\InstallOperation $operation */
			$package = $operation->getPackage();
		} else {
			/** @var \Composer\DependencyResolver\Operation\UpdateOperation $operation */
			$package = $operation->getTargetPackage();
		}

		/** @var \Composer\Package\Package $package */
		if ($package->getName() === 'squizlabs/php_codesniffer') {
			static::patchCodesniffer($vendorDir, $package);
		}
	}

	/**
	 * Patch PHP Codesniffer to use Nadybot style by default
	 *
	 * @param string                    $vendorDir The installation basepath
	 * @param \Composer\Package\Package $package   The package being installed
	 */
	public static function patchCodesniffer($vendorDir, Package $package): void {
		$file = $vendorDir . '/' . $package->getName() . '/CodeSniffer.conf.dist';
		$oldContent = file_get_contents($file);
		if ($oldContent === false) {
			return;
		}
		$newContent = "__DIR__.'/../../../style/Nadybot/ruleset.xml'";
		$data = preg_replace("/'PSR2'/", $newContent, $oldContent);
		$data = preg_replace("/(?<='show_warnings' => ')0/", "1", $data);
		$newFile = $vendorDir . '/' . $package->getName() . '/CodeSniffer.conf';
		file_put_contents($newFile, $data);
	}
}
