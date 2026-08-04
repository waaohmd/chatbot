@echo off
setlocal

cd /d "%~dp0"

where npm >nul 2>nul
if errorlevel 1 (
  echo npm was not found. Install Node.js, then run this file again.
  pause
  exit /b 1
)

if not exist "node_modules\next\package.json" (
  echo Installing chatbot admin dependencies...
  call npm install
  if errorlevel 1 (
    echo Dependency installation failed.
    pause
    exit /b 1
  )
)

echo Starting Chatbot Admin at http://localhost:3010/local-admin
call npm run dev

pause
