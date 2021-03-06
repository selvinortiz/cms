<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\records;

use craft\db\ActiveRecord;

/**
 * Class AssetTransform record.
 *
 * @property int       $id                  ID
 * @property string    $name                Name
 * @property string    $handle              Handle
 * @property string    $mode                Mode
 * @property string    $position            Position
 * @property int       $height              Height
 * @property int       $width               Width
 * @property string    $format              Format
 * @property int       $quality             Quality
 * @property \DateTime $dimensionChangeTime Dimension change time
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class AssetTransform extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string
     */
    public static function tableName(): string
    {
        return '{{%assettransforms}}';
    }
}
