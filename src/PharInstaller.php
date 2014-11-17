<?php

namespace hco\ComposerPharInstaller;

use Composer\Composer;
use Composer\Installer\InstallerInterface;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

class PharInstaller extends LibraryInstaller implements InstallerInterface
{
    public function __construct(IOInterface $io, Composer $composer, Filesystem $filesystem = null)
    {
        parent::__construct(
            $io,
            $composer,
            'toolphar',
            $filesystem
        );
    }

    protected function _getPharPackageInfo(PackageInterface $package, $absoluteLink = false)
    {
        if (empty($this->binDir)) {
            $this->initializeBinDir();
        }

        $binPath = '';
        $bin     = '';
        $link    = '';

        $phars = glob($this->getInstallPath($package) . '/*.phar');

        if (!empty($phars)) {
            $packageExtra = $package->getExtra();

            $binPath = realpath(reset($phars));
            $bin = isset($packageExtra['bin-name']) ? $packageExtra['bin-name'] : basename($binPath);

            if ($absoluteLink) {
                $this->initializeBinDir();
            }

            $link = $this->binDir . '/' . $bin;
        }

        return array(
            'binPath' => $binPath,
            'bin'     => $bin,
            'link'    => $link
        );
    }

    protected function installBinaries(PackageInterface $package)
    {
        parent::installBinaries($package);

        // Extract the components of the returned array into PHP variables.
        extract($this->_getPharPackageInfo($package, true));

        // Install link
        if (!empty($binPath)) {
            if (file_exists($link)) {
                if (is_link($link)) {
                    // likely leftover from a previous install, make sure
                    // that the target is still executable in case this
                    // is a fresh install of the vendor.
                    @chmod($link, 0777 & ~umask());
                }
                $this->io->write('    Skipped installation of bin '.$bin.' for package '.$package->getName().': name conflicts with an existing file');
                return;
            }

            if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                // add unixy support for cygwin and similar environments
                if ('.bat' !== substr($binPath, -4)) {
                    file_put_contents($link, $this->generateUnixyProxyCode($binPath, $link));
                    @chmod($link, 0777 & ~umask());
                    $link .= '.bat';
                    if (file_exists($link)) {
                        $this->io->write('    Skipped installation of bin '.$bin.'.bat proxy for package '.$package->getName().': a .bat proxy was already installed');
                    }
                }
                if (!file_exists($link)) {
                    file_put_contents($link, $this->generateWindowsProxyCode($binPath, $link));
                }
            } else {
                $cwd = getcwd();
                try {
                    // under linux symlinks are not always supported for example
                    // when using it in smbfs mounted folder
                    $relativeBin = $this->filesystem->findShortestPath($link, $binPath);
                    chdir(dirname($link));
                    if (false === symlink($relativeBin, $link)) {
                        throw new \ErrorException();
                    }
                } catch (\ErrorException $e) {
                    file_put_contents($link, $this->generateUnixyProxyCode($binPath, $link));
                }
                chdir($cwd);
            }

            @chmod($link, 0777 & ~umask());
        }
    }

    protected function removeBinaries(PackageInterface $package)
    {
        parent::removeBinaries($package);

        // Extract the components of the returned array into PHP variables.
        extract($this->_getPharPackageInfo($package));

        // Remove any existing links to PHAR
        if (is_link($link) || file_exists($link)) {
            $this->filesystem->unlink($link);
        }
        if (file_exists($link.'.bat')) {
            $this->filesystem->unlink($link.'.bat');
        }
    }
}
