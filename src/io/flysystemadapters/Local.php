<?php
namespace craft\app\io\flysystemadapters;

use \League\Flysystem\Adapter\Local as LocalAdapter;

/**
 * Local folder Flysystem adapter
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2014, Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license Craft License Agreement
 * @see       http://buildwithcraft.com
 * @package   craft.app.flysystemadapters
 * @since     3.0
 */
class Local extends LocalAdapter implements IFlysystemAdapter
{
	// Public Methods
	// =========================================================================
	/**
	 * Renames a directory.
	 *
	 * @param string $path The old path of the file, relative to the source’s root.
	 * @param string $newPath The new path of the file, relative to the source’s root.
	 *
	 * @return bool Whether the operation was successful.
	 */
	public function renameDir($path, $newPath)
	{
		// TODO: Implement renameDir() method.
	}
}