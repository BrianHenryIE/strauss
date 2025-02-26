<?php
/**
 * @author BrianHenryIE
 */

namespace BrianHenryIE\Strauss;

use ArrayIterator;
use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Pipeline\Licenser;
use BrianHenryIE\Strauss\TestCase;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Local\LocalFilesystemAdapter;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Mockery;

/**
 * Class LicenserTest
 * @package BrianHenryIE\Strauss
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Licenser
 */
class LicenserTest extends TestCase
{
    /**
     * @covers ::findLicenseFiles
     */
    public function testFindLicenceFilesPathsAreRelative()
    {
        $config = $this->createStub(StraussConfig::class);

        $dependencies = array();

        $dependency = $this->createStub(ComposerPackage::class);
        $dependency->method('getRelativePath')->willReturn('developer-name/project-name/');
        $dependency->method('getPackageAbsolutePath')->willReturn(__DIR__.'/vendor/developer-name/project-name/');
        $dependencies[] = $dependency;

        $filesystemMock = Mockery::mock(FileSystem::class);

        $file = Mockery::mock(FileAttributes::class);
        $file->expects('path')
             ->andReturn(__DIR__.'/vendor/developer-name/project-name/license.md');

        $finderArrayIterator = new ArrayIterator(array(
            $file
        ));

        $directoryListingMock = Mockery::mock(DirectoryListing::class);
        $directoryListingMock->expects('getIterator')->andReturn($finderArrayIterator);

        $filesystemMock->expects('listContents')->andReturn($directoryListingMock);

        $sut = new Licenser($config, $dependencies, 'BrianHenryIE', $filesystemMock);

        $sut->findLicenseFiles();

        $result = $sut->getDiscoveredLicenseFiles();

        // Currently contains an array entry: /Users/brianhenry/Sites/mozart/mozart/tests/Unit/developer-name/project-name/license.md
        self::assertStringContainsString('developer-name/project-name/license.md', $result[0]);
    }

    /**
     * Licence files should be found regardless of case and regardless of British/US-English spelling.
     *
     * @see https://www.phpliveregex.com/p/A5y
     */

    /**
     * @see https://github.com/AuthorizeNet/sdk-php/blob/a3e76f96f674d16e892f87c58bedb99dada4b067/lib/net/authorize/api/contract/v1/ANetApiRequestType.php
     *
     * @covers ::addChangeDeclarationToPhpString
     */
    public function testAppendHeaderCommentInformationNoHeader()
    {
        $author = 'BrianHenryIE';

        $config = $this->createMock(StraussConfig::class);
        $config->expects($this->once())->method('isIncludeModifiedDate')->willReturn(true);
        $config->expects($this->once())->method('isIncludeAuthor')->willReturn(true);

        $sut = new Licenser($config, array(), $author, $this->getFileSystem());

        $contents = <<<'EOD'
<?php

namespace net\authorize\api\contract\v1;
EOD;

        //     "license": "proprietary",

        $expected = <<<'EOD'
<?php
/**
 * @license proprietary
 *
 * Modified by BrianHenryIE on 25-April-2021 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace net\authorize\api\contract\v1;
EOD;

        $actual = $sut->addChangeDeclarationToPhpString(
            $contents,
            '25-April-2021',
            'authorizenet/authorizenet',
            'proprietary'
        );

        self::assertEqualsRN($expected, $actual);
    }


    // https://schibsted.com/blog/mocking-the-file-system-using-phpunit-and-vfsstream/

    /**
     * Not including the date was reported as not working.
     * The real problem was the master readme was ahead of the packagist release.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/35
     *
     * @covers ::addChangeDeclarationToPhpString
     */
    public function testAppendHeaderCommentNoDate()
    {

        $author = 'BrianHenryIE';

        $config = $this->createMock(StraussConfig::class);
        $config->expects($this->once())->method('isIncludeModifiedDate')->willReturn(false);
        $config->expects($this->once())->method('isIncludeAuthor')->willReturn(true);

        $sut = new Licenser($config, array(), $author, $this->getFileSystem());

        $contents = <<<'EOD'
<?php

namespace net\authorize\api\contract\v1;
EOD;

        $expected = <<<'EOD'
<?php
/**
 * @license proprietary
 *
 * Modified by BrianHenryIE using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace net\authorize\api\contract\v1;
EOD;

        $actual = $sut->addChangeDeclarationToPhpString(
            $contents,
            '25-April-2021',
            'authorizenet/authorizenet',
            'proprietary'
        );

        self::assertEqualsRN($expected, $actual);
    }

    /**
     * @covers ::addChangeDeclarationToPhpString
     */
    public function testAppendHeaderCommentNoAuthor()
    {

        $author = 'BrianHenryIE';

        $config = $this->createMock(StraussConfig::class);
        $config->expects($this->once())->method('isIncludeAuthor')->willReturn(false);

        $sut = new Licenser($config, array(), $author, $this->getFileSystem());

        $contents = <<<'EOD'
<?php

namespace net\authorize\api\contract\v1;
EOD;

        $expected = <<<'EOD'
<?php
/**
 * @license proprietary
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace net\authorize\api\contract\v1;
EOD;

        $actual = $sut->addChangeDeclarationToPhpString(
            $contents,
            '25-April-2021',
            'authorizenet/authorizenet',
            'proprietary'
        );

        self::assertEqualsRN($expected, $actual);
    }

    /**
     * @covers ::addChangeDeclarationToPhpString
     */
    public function testWithLicenceAlreadyInHeader(): void
    {

        $config = $this->createMock(StraussConfig::class);
        $config->expects($this->once())->method('isIncludeModifiedDate')->willReturn(true);
        $config->expects($this->once())->method('isIncludeAuthor')->willReturn(true);

        $author = 'BrianHenryIE';
        $sut = new Licenser($config, array(), $author, $this->getFileSystem());

        $contents = <<<'EOD'
<?php // phpcs:ignore WordPress.Files.FileName
/**
 * Handles dismissing admin notices.
 *
 * @package   WPTRT/admin-notices
 * @author    WPTRT <themes@wordpress.org>
 * @copyright 2019 WPTRT
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 * @link      https://github.com/WPTRT/admin-notices
 */

namespace Yeah;
EOD;

        $expected = <<<'EOD'
<?php // phpcs:ignore WordPress.Files.FileName
/**
 * Handles dismissing admin notices.
 *
 * @package   WPTRT/admin-notices
 * @author    WPTRT <themes@wordpress.org>
 * @copyright 2019 WPTRT
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 * @link      https://github.com/WPTRT/admin-notices
 *
 * Modified by BrianHenryIE on 25-April-2021 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Yeah;
EOD;

        $actual = $sut->addChangeDeclarationToPhpString(
            $contents,
            '25-April-2021',
            'wptrt/admin-notices',
            'GPL-2.0-or-later'
        );

        self::assertEqualsRN($expected, $actual);
    }


    /**
     * Shouldn't matter too much but y'know regexes.
     *
     * @covers ::addChangeDeclarationToPhpString
     */
    public function testWithTwoCommentsBeforeFirstCode()
    {

        $config = $this->createMock(StraussConfig::class);
        $config->expects($this->once())->method('isIncludeModifiedDate')->willReturn(true);
        $config->expects($this->once())->method('isIncludeAuthor')->willReturn(true);

        $author = 'BrianHenryIE';
        $sut = new Licenser($config, array(), $author, $this->getFileSystem());

        $contents = <<<'EOD'
<?php
/**
 * WP Dependency Installer
 *
 * A lightweight class to add to WordPress plugins or themes to automatically install
 * required plugin dependencies. Uses a JSON config file to declare plugin dependencies.
 * It can install a plugin from w.org, GitHub, Bitbucket, GitLab, Gitea or direct URL.
 *
 * @package   BH_WC_Auto_Print_Shipping_Labels_Receipts_WP_Dependency_Installer
 * @author    Andy Fragen, Matt Gibbs, Raruto
 * @license   MIT
 * @link      https://github.com/afragen/wp-dependency-installer
 */

/**
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
EOD;

        $expected = <<<'EOD'
<?php
/**
 * WP Dependency Installer
 *
 * A lightweight class to add to WordPress plugins or themes to automatically install
 * required plugin dependencies. Uses a JSON config file to declare plugin dependencies.
 * It can install a plugin from w.org, GitHub, Bitbucket, GitLab, Gitea or direct URL.
 *
 * @package   BH_WC_Auto_Print_Shipping_Labels_Receipts_WP_Dependency_Installer
 * @author    Andy Fragen, Matt Gibbs, Raruto
 * @license   MIT
 * @link      https://github.com/afragen/wp-dependency-installer
 *
 * Modified by BrianHenryIE on 25-April-2021 using {@see https://github.com/BrianHenryIE/strauss}.
 */

/**
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
EOD;

        foreach (range(0, 3) as $_) {
            $contents = $sut->addChangeDeclarationToPhpString(
                $contents,
                '25-April-2021',
                'afragen/wp-dependency-installer',
                'MIT'
            );
        }

        self::assertEqualsRN($expected, $contents);
    }

    /**
     * @covers ::addChangeDeclarationToPhpString
     */
    public function testUnusualHeaderCommentStyle()
    {

        $config = $this->createMock(StraussConfig::class);
        $config->expects($this->once())->method('isIncludeModifiedDate')->willReturn(true);
        $config->expects($this->once())->method('isIncludeAuthor')->willReturn(true);

        $author = 'BrianHenryIE';
        $sut = new Licenser($config, array(), $author, $this->getFileSystem());

        $contents = <<<'EOD'
<?php
/*******************************************************************************
* FPDF                                                                         *
*                                                                              *
* Version: 1.82                                                                *
* Date:    2019-12-07                                                          *
* Author:  Olivier PLATHEY                                                     *
*******************************************************************************/

define('FPDF_VERSION','1.82');
EOD;

        $expected = <<<'EOD'
<?php
/*******************************************************************************
* FPDF                                                                         *
*                                                                              *
* Version: 1.82                                                                *
* Date:    2019-12-07                                                          *
* Author:  Olivier PLATHEY                                                     *
*******************************************************************************
* @license proprietary
* Modified by BrianHenryIE on 25-April-2021 using {@see https://github.com/BrianHenryIE/strauss}.
*/

define('FPDF_VERSION','1.82');
EOD;

        foreach (range(0, 3) as $_) {
            $contents = $sut->addChangeDeclarationToPhpString(
                $contents,
                '25-April-2021',
                'setasign/fpdf',
                'proprietary'
            );
        }

        self::assertEqualsRN($expected, $contents);
    }

    /**
     * @covers ::addChangeDeclarationToPhpString
     */
    public function testCommentWithLicenseWord()
    {

        $config = $this->createMock(StraussConfig::class);
        $config->expects($this->once())->method('isIncludeModifiedDate')->willReturn(true);
        $config->expects($this->once())->method('isIncludeAuthor')->willReturn(true);

        $author = 'BrianHenryIE';
        $sut = new Licenser($config, array(), $author, $this->getFileSystem());

        $contents = <<<'EOD'
<?php

/**
 * Assert
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Your_Domain\Assert;
EOD;

        $expected = <<<'EOD'
<?php

/**
 * Assert
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 *
 * Modified by BrianHenryIE on 25-April-2021 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Your_Domain\Assert;
EOD;

        foreach (range(0, 3) as $_) {
            $contents = $sut->addChangeDeclarationToPhpString(
                $contents,
                '25-April-2021',
                '',
                'MIT'
            );
        }

        self::assertEqualsRN($expected, $contents);
    }

    /**
     * This was matching the "no header comment" regex.
     *
     * FOCK: The test passed. How do I debug when the test passes?! The test is passing but actual output is incorrect.
     * @see https://www.youtube.com/watch?v=QnxpHIl5Ynw
     *
     * Seems files loaded are treated different to strings passed.
     */
    public function testIncorrectlyMatching()
    {

        $config = $this->createMock(StraussConfig::class);
        $config->expects($this->once())->method('isIncludeModifiedDate')->willReturn(true);
        $config->expects($this->once())->method('isIncludeAuthor')->willReturn(true);

        $author = 'BrianHenryIE';
        $sut = new Licenser($config, array(), $author, $this->getFileSystem());

        $contents = <<<'EOD'
<?php
/**
 * WP Dependency Installer
 *
 * A lightweight class to add to WordPress plugins or themes to automatically install
 * required plugin dependencies. Uses a JSON config file to declare plugin dependencies.
 * It can install a plugin from w.org, GitHub, Bitbucket, GitLab, Gitea or direct URL.
 *
 * @package   BH_WC_Auto_Purchase_EasyPost_WP_Dependency_Installer
 * @author    Andy Fragen, Matt Gibbs, Raruto
 * @license   MIT
 * @link      https://github.com/afragen/wp-dependency-installer
 */

/**
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}
EOD;

        // Attempt to replicate the failing test, since the contents seem the same but the input manner is different.
        $tmpfname = tempnam(sys_get_temp_dir(), 'Strauss-' . __CLASS__ . '-' . __FUNCTION__);
        file_put_contents($tmpfname, $contents);
        $contents = file_get_contents($tmpfname);

        $expected = <<<'EOD'
<?php
/**
 * WP Dependency Installer
 *
 * A lightweight class to add to WordPress plugins or themes to automatically install
 * required plugin dependencies. Uses a JSON config file to declare plugin dependencies.
 * It can install a plugin from w.org, GitHub, Bitbucket, GitLab, Gitea or direct URL.
 *
 * @package   BH_WC_Auto_Purchase_EasyPost_WP_Dependency_Installer
 * @author    Andy Fragen, Matt Gibbs, Raruto
 * @license   MIT
 * @link      https://github.com/afragen/wp-dependency-installer
 *
 * Modified by BrianHenryIE on 25-April-2021 using {@see https://github.com/BrianHenryIE/strauss}.
 */

/**
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}
EOD;

        $actual = $sut->addChangeDeclarationToPhpString(
            $contents,
            '25-April-2021',
            'afragen/wp-dependency-installer',
            'MIT'
        );

        self::assertEqualsRN($expected, $actual);
    }

    /**
     * The licence was being inserted after every `<?php` in the file.
     */
    public function testLicenseDetailsOnlyInsertedOncePerFile()
    {
        $config = $this->createMock(StraussConfig::class);
        $config->expects($this->once())->method('isIncludeModifiedDate')->willReturn(true);
        $config->expects($this->once())->method('isIncludeAuthor')->willReturn(true);

        $author = 'BrianHenryIE';
        $sut = new Licenser($config, array(), $author, $this->getFileSystem());

        $contents = <<<'EOD'
<?php

?>

<?php

?>
EOD;

        $expected = <<<'EOD'
<?php
/**
 * @license MIT
 *
 * Modified by BrianHenryIE on 25-April-2021 using {@see https://github.com/BrianHenryIE/strauss}.
 */

?>

<?php

?>
EOD;

        foreach (range(0, 3) as $_) {
            $contents = $sut->addChangeDeclarationToPhpString(
                $contents,
                '25-April-2021',
                '',
                'MIT'
            );
        }

        self::assertEqualsRN($expected, $contents);
    }
}
