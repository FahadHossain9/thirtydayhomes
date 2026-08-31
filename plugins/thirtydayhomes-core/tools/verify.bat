@echo off
REM ===================================================================
REM  Run the local verification checks.
REM
REM  Double-click it, or from any directory:
REM      verify.bat
REM
REM  XAMPP does not put php.exe on the PATH, so "php wp-cli.phar ..."
REM  fails with "term 'php' is not recognized". This finds the binaries
REM  instead of assuming they are on the PATH.
REM
REM  Override for a different setup:
REM      set TDH_PHP=C:\php\php.exe
REM      set TDH_WPCLI=C:\tools\wp-cli.phar
REM      set TDH_WP=C:\sites\thirtydayhomes
REM      verify.bat
REM ===================================================================

setlocal

if "%TDH_PHP%"==""   set "TDH_PHP=D:\xampp\php\php.exe"
if "%TDH_WPCLI%"=="" set "TDH_WPCLI=D:\xampp\wp-cli.phar"
if "%TDH_WP%"==""    set "TDH_WP=D:\xampp\htdocs\thirtydayhomes"

if not exist "%TDH_PHP%" (
	echo.
	echo   php.exe not found at: %TDH_PHP%
	echo   Set TDH_PHP to its real location and run again.
	echo.
	exit /b 1
)

if not exist "%TDH_WPCLI%" (
	echo.
	echo   wp-cli.phar not found at: %TDH_WPCLI%
	echo   Set TDH_WPCLI to its real location and run again.
	echo.
	exit /b 1
)

if not exist "%TDH_WP%\wp-load.php" (
	echo.
	echo   No WordPress install at: %TDH_WP%
	echo   Set TDH_WP to the folder containing wp-load.php.
	echo.
	exit /b 1
)

REM wp-cli must run from the WordPress root to find wp-config.php.
pushd "%TDH_WP%"

"%TDH_PHP%" "%TDH_WPCLI%" eval-file "wp-content/plugins/thirtydayhomes-core/tools/verify.php"
set "RESULT=%ERRORLEVEL%"

REM The feature suites run whatever the first one did, so one failure does
REM not hide the state of everything else. Their exit codes are folded in.
"%TDH_PHP%" "%TDH_WPCLI%" eval-file "wp-content/plugins/thirtydayhomes-core/tools/verify-contact.php"
if not "%ERRORLEVEL%"=="0" set "RESULT=%ERRORLEVEL%"

"%TDH_PHP%" "%TDH_WPCLI%" eval-file "wp-content/plugins/thirtydayhomes-core/tools/verify-delivery.php"
if not "%ERRORLEVEL%"=="0" set "RESULT=%ERRORLEVEL%"

"%TDH_PHP%" "%TDH_WPCLI%" eval-file "wp-content/plugins/thirtydayhomes-core/tools/verify-page-widgets.php"
if not "%ERRORLEVEL%"=="0" set "RESULT=%ERRORLEVEL%"

popd

echo.
if not "%RESULT%"=="0" (
	echo   Checks did not complete cleanly. See the output above.
) else (
	echo   Done.
)

REM Keep the window open when double-clicked from Explorer, otherwise the
REM results vanish with the window. PowerShell launches a .bat the same way
REM Explorer does, so the two cannot be told apart reliably — set
REM TDH_NOPAUSE=1 to skip the prompt when calling this from a script or a
REM terminal you are already sitting in.
if "%TDH_NOPAUSE%"=="" (
	echo %CMDCMDLINE% | find /i "%~0" >nul
	if not errorlevel 1 pause
)

exit /b %RESULT%
