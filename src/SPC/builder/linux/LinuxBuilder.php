<?php

declare(strict_types=1);

namespace SPC\builder\linux;

use SPC\builder\BuilderBase;
use SPC\builder\linux\library\LinuxLibraryBase;
use SPC\builder\traits\UnixBuilderTrait;
use SPC\exception\FileSystemException;
use SPC\exception\RuntimeException;
use SPC\exception\WrongUsageException;
use SPC\store\FileSystem;
use SPC\store\SourcePatcher;

class LinuxBuilder extends BuilderBase
{
    /** Unix compatible builder methods */
    use UnixBuilderTrait;

    /** @var array Tune cflags */
    public array $tune_c_flags;

    /** @var string pkg-config env, including PKG_CONFIG_PATH, PKG_CONFIG */
    public string $pkgconf_env;

    /** @var bool Micro patch phar flag */
    private bool $phar_patched = false;

    /**
     * @throws FileSystemException
     * @throws RuntimeException
     * @throws WrongUsageException
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;

        // ---------- set necessary options ----------
        // set C/C++ compilers (default: alpine: gcc, others: musl-cross-make)
        if (SystemUtil::isMuslDist()) {
            $this->setOptionIfNotExist('cc', 'gcc');
            $this->setOptionIfNotExist('cxx', 'g++');
            $this->setOptionIfNotExist('ar', 'ar');
            $this->setOptionIfNotExist('ld', 'ld.gold');
            $this->setOptionIfNotExist('library_path', '');
            $this->setOptionIfNotExist('ld_library_path', '');
        } else {
            $arch = arch2gnu(php_uname('m'));
            $this->setOptionIfNotExist('cc', "{$arch}-linux-musl-gcc");
            $this->setOptionIfNotExist('cxx', "{$arch}-linux-musl-g++");
            $this->setOptionIfNotExist('ar', "{$arch}-linux-musl-ar");
            $this->setOptionIfNotExist('ld', "/usr/local/musl/{$arch}-linux-musl/bin/ld.gold");
            $this->setOptionIfNotExist('library_path', "LIBRARY_PATH=/usr/local/musl/{$arch}-linux-musl/lib");
            $this->setOptionIfNotExist('ld_library_path', "LD_LIBRARY_PATH=/usr/local/musl/{$arch}-linux-musl/lib");
        }
        // set arch (default: current)
        $this->setOptionIfNotExist('arch', php_uname('m'));
        $this->setOptionIfNotExist('gnu-arch', arch2gnu($this->getOption('arch')));

        // concurrency
        $this->concurrency = SystemUtil::getCpuCount();
        // cflags
        $this->arch_c_flags = SystemUtil::getArchCFlags($this->getOption('cc'), $this->getOption('arch'));
        $this->arch_cxx_flags = SystemUtil::getArchCFlags($this->getOption('cxx'), $this->getOption('arch'));
        $this->tune_c_flags = SystemUtil::checkCCFlags(SystemUtil::getTuneCFlags($this->getOption('arch')), $this->getOption('cc'));
        // cmake toolchain
        $this->cmake_toolchain_file = SystemUtil::makeCmakeToolchainFile(
            'Linux',
            $this->getOption('arch'),
            $this->arch_c_flags,
            $this->getOption('cc'),
            $this->getOption('cxx'),
        );
        // pkg-config
        $vars = [
            'PKG_CONFIG' => BUILD_ROOT_PATH . '/bin/pkg-config',
            'PKG_CONFIG_PATH' => BUILD_LIB_PATH . '/pkgconfig',
        ];
        $this->pkgconf_env = SystemUtil::makeEnvVarString($vars);
        // configure environment
        $this->configure_env = SystemUtil::makeEnvVarString([
            ...$vars,
            'CC' => $this->getOption('cc'),
            'CXX' => $this->getOption('cxx'),
            'AR' => $this->getOption('ar'),
            'LD' => $this->getOption('ld'),
            'PATH' => BUILD_ROOT_PATH . '/bin:' . getenv('PATH'),
        ]);
        // cross-compiling is not supported yet
        /*if (php_uname('m') !== $this->arch) {
            $this->cross_compile_prefix = SystemUtil::getCrossCompilePrefix($this->cc, $this->arch);
            logger()->info('using cross compile prefix: ' . $this->cross_compile_prefix);
            $this->configure_env .= " CROSS_COMPILE='{$this->cross_compile_prefix}'";
        }*/

        // create pkgconfig and include dir (some libs cannot create them automatically)
        f_mkdir(BUILD_LIB_PATH . '/pkgconfig', recursive: true);
        f_mkdir(BUILD_INCLUDE_PATH, recursive: true);
    }

    /**
     * @throws FileSystemException
     * @throws RuntimeException
     * @throws WrongUsageException
     */
    public function makeAutoconfArgs(string $name, array $libSpecs): string
    {
        $ret = '';
        foreach ($libSpecs as $libName => $arr) {
            $lib = $this->getLib($libName);

            $arr = $arr ?? [];

            $disableArgs = $arr[0] ?? null;
            $prefix = $arr[1] ?? null;
            if ($lib instanceof LinuxLibraryBase) {
                logger()->info("{$name} \033[32;1mwith\033[0;1m {$libName} support");
                $ret .= $lib->makeAutoconfEnv($prefix) . ' ';
            } else {
                logger()->info("{$name} \033[31;1mwithout\033[0;1m {$libName} support");
                $ret .= ($disableArgs ?? "--with-{$libName}=no") . ' ';
            }
        }
        return rtrim($ret);
    }

    /**
     * @throws RuntimeException
     * @throws FileSystemException
     * @throws WrongUsageException
     */
    public function buildPHP(int $build_target = BUILD_TARGET_NONE): void
    {
        // ---------- Update extra-libs ----------
        $extra_libs = $this->getOption('extra-libs', '');
        // non-bloat linking
        if (!$this->getOption('bloat', false)) {
            $extra_libs .= (empty($extra_libs) ? '' : ' ') . implode(' ', $this->getAllStaticLibFiles());
        } else {
            $extra_libs .= (empty($extra_libs) ? '' : ' ') . implode(' ', array_map(fn ($x) => "-Xcompiler {$x}", array_filter($this->getAllStaticLibFiles())));
        }
        // add libstdc++, some extensions or libraries need it
        $extra_libs .= (empty($extra_libs) ? '' : ' ') . ($this->hasCppExtension() ? '-lstdc++ ' : '');
        $this->setOption('extra-libs', $extra_libs);

        $cflags = $this->arch_c_flags;
        $use_lld = '';
        if (str_ends_with($this->getOption('cc'), 'clang') && SystemUtil::findCommand('lld')) {
            $use_lld = '-Xcompiler -fuse-ld=lld';
        }

        $envs = $this->pkgconf_env . ' ' . SystemUtil::makeEnvVarString([
            'CC' => $this->getOption('cc'),
            'CXX' => $this->getOption('cxx'),
            'AR' => $this->getOption('ar'),
            'LD' => $this->getOption('ld'),
            'CFLAGS' => $cflags,
            'LIBS' => '-ldl -lpthread',
            'PATH' => BUILD_ROOT_PATH . '/bin:' . getenv('PATH'),
        ]);

        SourcePatcher::patchBeforeBuildconf($this);

        shell()->cd(SOURCE_PATH . '/php-src')->exec('./buildconf --force');

        SourcePatcher::patchBeforeConfigure($this);

        $phpVersionID = $this->getPHPVersionID();
        $json_74 = $phpVersionID < 80000 ? '--enable-json ' : '';

        if ($this->getOption('enable-zts', false)) {
            $maxExecutionTimers = $phpVersionID >= 80100 ? '--enable-zend-max-execution-timers ' : '';
            $zts = '--enable-zts --disable-zend-signals ';
        } else {
            $maxExecutionTimers = '';
            $zts = '';
        }
        $disable_jit = $this->getOption('disable-opcache-jit', false) ? '--disable-opcache-jit ' : '';

        $enableCli = ($build_target & BUILD_TARGET_CLI) === BUILD_TARGET_CLI;
        $enableFpm = ($build_target & BUILD_TARGET_FPM) === BUILD_TARGET_FPM;
        $enableMicro = ($build_target & BUILD_TARGET_MICRO) === BUILD_TARGET_MICRO;
        $enableEmbed = ($build_target & BUILD_TARGET_EMBED) === BUILD_TARGET_EMBED;

        shell()->cd(SOURCE_PATH . '/php-src')
            ->exec(
                "{$this->getOption('ld_library_path')} " .
                './configure ' .
                '--prefix= ' .
                '--with-valgrind=no ' .
                '--enable-shared=no ' .
                '--enable-static=yes ' .
                '--disable-all ' .
                '--disable-cgi ' .
                '--disable-phpdbg ' .
                ($enableCli ? '--enable-cli ' : '--disable-cli ') .
                ($enableFpm ? '--enable-fpm ' : '--disable-fpm ') .
                ($enableEmbed ? '--enable-embed=static ' : '--disable-embed ') .
                ($enableMicro ? '--enable-micro=all-static ' : '--disable-micro ') .
                $disable_jit .
                $json_74 .
                $zts .
                $maxExecutionTimers .
                $this->makeExtensionArgs() . ' ' .
                $envs
            );

        SourcePatcher::patchBeforeMake($this);

        $this->cleanMake();

        if ($enableCli) {
            logger()->info('building cli');
            $this->buildCli($use_lld);
        }
        if ($enableFpm) {
            logger()->info('building fpm');
            $this->buildFpm($use_lld);
        }
        if ($enableMicro) {
            logger()->info('building micro');
            $this->buildMicro($use_lld, $cflags);
        }
        if ($enableEmbed) {
            logger()->info('building embed');
            if ($enableMicro) {
                FileSystem::replaceFileStr(SOURCE_PATH . '/php-src/Makefile', 'OVERALL_TARGET =', 'OVERALL_TARGET = libphp.la');
            }
            $this->buildEmbed($use_lld);
        }

        if (php_uname('m') === $this->getOption('arch')) {
            $this->sanityCheck($build_target);
        }
    }

    /**
     * Build cli sapi
     *
     * @throws RuntimeException
     * @throws FileSystemException
     */
    public function buildCli(string $use_lld): void
    {
        $vars = SystemUtil::makeEnvVarString([
            'EXTRA_CFLAGS' => '-g -Os -fno-ident -fPIE ' . implode(' ', array_map(fn ($x) => "-Xcompiler {$x}", $this->tune_c_flags)),
            'EXTRA_LIBS' => $this->getOption('extra-libs', ''),
            'EXTRA_LDFLAGS_PROGRAM' => "{$use_lld} -all-static",
        ]);
        shell()->cd(SOURCE_PATH . '/php-src')
            ->exec('sed -i "s|//lib|/lib|g" Makefile')
            ->exec("make -j{$this->concurrency} {$vars} cli");

        if (!$this->getOption('no-strip', false)) {
            shell()->cd(SOURCE_PATH . '/php-src/sapi/cli')->exec('strip --strip-all php');
        }

        $this->deployBinary(BUILD_TARGET_CLI);
    }

    /**
     * Build phpmicro sapi
     *
     * @throws RuntimeException
     * @throws FileSystemException
     */
    public function buildMicro(string $use_lld, string $cflags): void
    {
        if ($this->getPHPVersionID() < 80000) {
            throw new RuntimeException('phpmicro only support PHP >= 8.0!');
        }
        if ($this->getExt('phar')) {
            $this->phar_patched = true;
            SourcePatcher::patchMicro(['phar']);
        }

        $enable_fake_cli = $this->getOption('with-micro-fake-cli', false) ? ' -DPHP_MICRO_FAKE_CLI' : '';
        $vars = SystemUtil::makeEnvVarString([
            'EXTRA_CFLAGS' => '-g -Os -fno-ident -fPIE ' . implode(' ', array_map(fn ($x) => "-Xcompiler {$x}", $this->tune_c_flags)) . $enable_fake_cli,
            'EXTRA_LIBS' => $this->getOption('extra-libs', ''),
            'EXTRA_LDFLAGS_PROGRAM' => "{$cflags} {$use_lld} -all-static",
        ]);
        shell()->cd(SOURCE_PATH . '/php-src')
            ->exec('sed -i "s|//lib|/lib|g" Makefile')
            ->exec("make -j{$this->concurrency} {$vars} micro");

        if (!$this->getOption('no-strip', false)) {
            shell()->cd(SOURCE_PATH . '/php-src/sapi/micro')->exec('strip --strip-all micro.sfx');
        }

        $this->deployBinary(BUILD_TARGET_MICRO);

        if ($this->phar_patched) {
            SourcePatcher::patchMicro(['phar'], true);
        }
    }

    /**
     * Build fpm sapi
     *
     * @throws FileSystemException
     * @throws RuntimeException
     */
    public function buildFpm(string $use_lld): void
    {
        $vars = SystemUtil::makeEnvVarString([
            'EXTRA_CFLAGS' => '-g -Os -fno-ident -fPIE ' . implode(' ', array_map(fn ($x) => "-Xcompiler {$x}", $this->tune_c_flags)),
            'EXTRA_LIBS' => $this->getOption('extra-libs', ''),
            'EXTRA_LDFLAGS_PROGRAM' => "{$use_lld} -all-static",
        ]);

        shell()->cd(SOURCE_PATH . '/php-src')
            ->exec('sed -i "s|//lib|/lib|g" Makefile')
            ->exec("make -j{$this->concurrency} {$vars} fpm");

        if (!$this->getOption('no-strip', false)) {
            shell()->cd(SOURCE_PATH . '/php-src/sapi/fpm')->exec('strip --strip-all php-fpm');
        }

        $this->deployBinary(BUILD_TARGET_FPM);
    }

    public function buildEmbed(string $use_lld): void
    {
        $vars = SystemUtil::makeEnvVarString([
            'EXTRA_CFLAGS' => '-g -Os -fno-ident -fPIE ' . implode(' ', array_map(fn ($x) => "-Xcompiler {$x}", $this->tune_c_flags)),
            'EXTRA_LIBS' => $this->getOption('extra-libs', ''),
            'EXTRA_LDFLAGS_PROGRAM' => "{$use_lld} -all-static",
        ]);

        shell()
            ->cd(SOURCE_PATH . '/php-src')
            ->exec('sed -i "s|//lib|/lib|g" Makefile')
            ->exec('make INSTALL_ROOT=' . BUILD_ROOT_PATH . " -j{$this->concurrency} {$vars} install");
    }
}
