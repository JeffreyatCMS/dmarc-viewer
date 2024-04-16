# Use the official PHP Apache image as the base image
FROM php:8.1-apache

# Update package lists and install Python and its pip package manager
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip

# PHP MYSQLI Extension
RUN docker-php-ext-install mysqli    
#Current Working Directory
WORKDIR /var/www/html
COPY requirements.txt .
COPY index.php .
COPY dmarc.py .
COPY rundmarc.php .
RUN chown www-data:www-data /var/www/html/*
RUN pip install -r requirements.txt --break-system-packages

# Expose port 80 for the Apache server
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2ctl", "-D", "FOREGROUND"]