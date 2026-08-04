@echo off
setlocal

set "ADMIN_DIR=%~dp0chatbot-admin"

where npm >nul 2>nul
if errorlevel 1 (
  echo npm was not found. Install Node.js, then run this file again.
  pause
  exit /b 1
)

if not exist "%ADMIN_DIR%\node_modules\next\package.json" (
  echo Installing chatbot admin dependencies...
  pushd "%ADMIN_DIR%"
  call npm install
  if errorlevel 1 (
    popd
    echo Dependency installation failed.
    pause
    exit /b 1
  )
  popd
)

echo Starting Chatbot Admin...
start "Chatbot Admin Server" /min "%ComSpec%" /k "cd /d ""%ADMIN_DIR%"" && npm run dev"

echo Waiting for the local server...
timeout /t 3 /nobreak >nul
start "" "http://localhost:3010/local-admin"
echo The admin page is opening in your browser.
exit /b 0
