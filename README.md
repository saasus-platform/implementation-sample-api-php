# implementation-sample-api-php

This is a SaaS implementation sample using the SaaSus SDK

See the documentation [API implementation using SaaS Platform](https://docs.saasus.io/ja/docs/implementation-guide/implementing-authentication-using-saasus-platform-apiserver)

## Run PHP API

```
git clone git@github.com:saasus-platform/implementation-sample-api-php.git
cd ./implementation-sample-api-php
```

```
cp .env.example .env
vi .env

# Set Env for SaaSus Platform API
# Get it in the SaaSus Admin Console
SAASUS_SAAS_ID="xxxxxxxxxx"
SAASUS_API_KEY="xxxxxxxxxx"
SAASUS_SECRET_KEY="xxxxxxxxxx"
SAASUS_AUTH_MODE="api"

# Save and exit
```

```
docker compose up -d --build
docker exec -it implementation-sample-api-php-app-1 bash
composer install
```
