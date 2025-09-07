# نبدأ بصورة فيها PHP 8.2 + FPM
FROM php:8.2-fpm

# نزود المكتبات اللي Laravel بيحتاجها عشان يشتغل
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring bcmath gd zip

# ننزل Composer (مدير الباكدج بتاع Laravel)
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

# نخلي مجلد العمل الأساسي هو /var/www
WORKDIR /var/www

# ننسخ ملفات المشروع كلها جوا السيرفر
COPY . .

# نسطب مكتبات Laravel (من composer.json)
RUN composer install --no-dev --optimize-autoloader

# ندي صلاحيات لمجلدات Laravel المهمة
RUN chmod -R 775 storage bootstrap/cache

# Laravel هيتشغل على بورت 8000
EXPOSE 8000

# الأمر اللي يشغّل المشروع
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
