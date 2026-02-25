@"
APP_ENV=dev
APP_SECRET=123456789abcdef

DATABASE_URL="mysql://root:@127.0.0.1:3306/decides_db?serverVersion=10.4"
MESSENGER_TRANSPORT_DSN=doctrine://default

###> symfony/lock ###
LOCK_DSN=flock
###< symfony/lock ###

OPENAI_API_KEY=sk-xxxxxxxxxxxxxxxxxxxxxxxx
OPENAI_MODEL=gpt-4o-mini

LOCATIONIQ_API_KEY=pk.123456789abcdef

GOOGLE_AI_API_KEY=AIzaSyCcgIm_EOFe0IDAge3ZxiICrMLvIBfioVI
GOOGLE_AI_MODEL=gemini-2.5-flash
MAILER_DSN=smtp://rimajelassi81%40gmail.com:xltomdqzxjahkbxy@smtp.gmail.com:587?encryption=tls&auth_mode=login
MAILER_FROM_ADDRESS=rimajelassi81@gmail.com
MAILER_FROM_NAME=Decides App
"@ | Out-File -FilePath "c:\Users\rimaj\OneDrive\Bureau\projet\.env" -Encoding utf8
