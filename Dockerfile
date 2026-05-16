############################################
# Base Image
############################################

# https://serversideup.net/open-source/docker-php/
FROM serversideup/php:8.4-fpm-nginx-alpine AS base

USER root
RUN install-php-extensions gd intl pcntl pdo_pgsql pgsql

############################################
# Vendor Image
############################################
FROM composer:latest AS vendor

COPY composer.json composer.lock ./

RUN composer install --prefer-dist --no-scripts --no-dev --no-autoloader --ignore-platform-reqs

############################################
# Frontend Build (Vite assets)
############################################
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build

############################################
# Development Image
############################################
FROM base AS development

ARG USER_ID
ARG GROUP_ID

USER root

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

USER www-data

############################################
# Staging Image
############################################
FROM base AS staging

USER www-data

############################################
# Production Image
############################################
FROM base AS deploy

COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor
COPY --chown=www-data:www-data . /var/www/html
COPY --from=frontend --chown=www-data:www-data /app/public/build /var/www/html/public/build

RUN composer dump-autoload --optimize

USER www-data
