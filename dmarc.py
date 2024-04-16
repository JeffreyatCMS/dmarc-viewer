import os
import requests
import mysql.connector
from azure.identity import ClientSecretCredential
from datetime import datetime, timedelta, timezone
import logging
import zipfile
import gzip
import shutil

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Function to create tables if they do not exist
def create_tables(cursor):
    try:
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS emails (
                id VARCHAR(255) PRIMARY KEY,
                subject VARCHAR(255),
                sender_email VARCHAR(255),
                received_datetime DATETIME,
                attachment_names TEXT
            )
        """)
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS attachments (
                email_id VARCHAR(255),
                name VARCHAR(255),
                content LONGBLOB,
                FOREIGN KEY (email_id) REFERENCES emails(id)
            )
        """)
    except mysql.connector.Error as err:
        logger.error(f"Error creating tables: {err}")

# Specify the directory to save attachments globally
attachments_dir = 'attachments'
if not os.path.exists(attachments_dir):
    os.makedirs(attachments_dir)

# Function to download and extract attachment content
def download_attachment(email_id, attachment_id, attachment_name, headers, SHARED_MAILBOX_EMAIL):
    """Download attachment content from Microsoft Graph API and save with correct file extension."""
    logger.info(f"Downloading attachment '{attachment_name}' with ID '{attachment_id}' from email '{email_id}'...")
    url = f'https://graph.microsoft.com/v1.0/users/{SHARED_MAILBOX_EMAIL}/messages/{email_id}/attachments/{attachment_id}/$value'
    response = requests.get(url, headers=headers)
    if response.status_code == 200:
        attachment_content = response.content
        attachment_filename = f"{attachments_dir}/{attachment_name}"

        # Save attachment content to file
        with open(attachment_filename, 'wb') as f:
            f.write(attachment_content)
        logger.info(f"Attachment '{attachment_name}' saved to '{attachment_filename}'.")

        # Extract the attachment if it's in zip or gz format
        if attachment_name.endswith('.zip'):
            try:
                with zipfile.ZipFile(attachment_filename, 'r') as zip_ref:
                    zip_ref.extractall(attachments_dir)
                os.remove(attachment_filename)  # Remove the .zip file after extraction
                logger.info(f"Zip file '{attachment_name}' extracted successfully.")
            except Exception as e:
                logger.error(f"Error extracting zip file '{attachment_filename}': {e}")
        elif attachment_name.endswith('.gz'):
            try:
                with gzip.open(attachment_filename, 'rb') as gz_ref:
                    with open(attachment_filename[:-3], 'wb') as f_out:
                        shutil.copyfileobj(gz_ref, f_out)
                os.remove(attachment_filename)  # Remove the .gz file after extraction
                logger.info(f"Gzip file '{attachment_name}' extracted successfully.")
            except Exception as e:
                logger.error(f"Error extracting gzip file '{attachment_filename}': {e}")

        return attachment_content, attachment_filename
    else:
        logger.error(f"Failed to download attachment '{attachment_name}' with ID '{attachment_id}' from '{email_id}'. Status code: {response.status_code}")
        return None, None

# Function to insert attachment data into the database
def insert_attachment(cursor, email_id, attachment_name, attachment_content):
    insert_query = "INSERT INTO attachments (email_id, name, content) VALUES (%s, %s, %s)"
    try:
        cursor.execute(insert_query, (email_id, attachment_name, attachment_content))
    except mysql.connector.Error as err:
        logger.error(f"Error inserting attachment into database: {err}")

# Main function
def main():
    # Azure AD authentication settings
    TENANT_ID = os.environ.get('TENANT_ID', 'default_value_if_not_set')
    CLIENT_ID = os.environ.get('CLIENT_ID', 'default_value_if_not_set')
    CLIENT_SECRET = os.environ.get('CLIENT_SECRET', 'default_value_if_not_set')

    # Email address of the shared mailbox
    SHARED_MAILBOX_EMAIL = os.environ.get('SHARED_MAILBOX_EMAIL', 'default_value_if_not_set')

    # MySQL database settings
    MYSQL_HOST = os.environ.get('MYSQL_HOST', 'default_value_if_not_set')
    MYSQL_USER = os.environ.get('MYSQL_USER', 'default_value_if_not_set')
    MYSQL_PASSWORD = os.environ.get('MYSQL_PASSWORD', 'default_value_if_not_set')
    MYSQL_DATABASE = os.environ.get('MYSQL_DATABASE', 'default_value_if_not_set')

    # Create a client secret credential
    credential = ClientSecretCredential(TENANT_ID, CLIENT_ID, CLIENT_SECRET)

    # Get an access token
    scope = ["https://graph.microsoft.com/.default"]
    token = credential.get_token(*scope)

    # Prepare headers with access token
    headers = {
        'Authorization': 'Bearer ' + token.token,
        'Content-Type': 'application/json'
    }
    # Specify parameters for fetching emails
    today = datetime.now(timezone.utc).strftime('%Y-%m-%dT00:00:00Z')
    month = (datetime.now(timezone.utc) - timedelta(30)).strftime('%Y-%m-%dT00:00:00Z')
    params = {
        '$filter': f"receivedDateTime ge {month} and receivedDateTime lt {today}",
        '$expand': 'attachments',  # Request to include attachments in the response,
        '$top': '200'
    }

    # Connect to MySQL database
    try:
        db_connection = mysql.connector.connect(
            host=MYSQL_HOST,
            user=MYSQL_USER,
            password=MYSQL_PASSWORD,
            database=MYSQL_DATABASE
        )
        cursor = db_connection.cursor()
        create_tables(cursor)  # Create tables if they do not exist
    except mysql.connector.Error as err:
        logger.error(f"Error connecting to MySQL database: {err}")
        exit(1)

    # Make request to Microsoft Graph API to fetch emails from shared mailbox
    url = f'https://graph.microsoft.com/v1.0/users/{SHARED_MAILBOX_EMAIL}/messages'
    response = requests.get(url, headers=headers, params=params)

    # Check if request was successful
    if response.status_code == 200:
        emails = response.json().get('value', [])
        
        for email in emails:
            logger.info(f"Email object: {email}")
            # Check if email with same ID already exists in the database
            cursor.execute("SELECT id FROM emails WHERE id = %s", (email.get('id', ''),))
            result = cursor.fetchone()
            if result:
                logger.info(f"Skipping email with ID '{email.get('id')}' as it already exists in the database.")
                continue

            # Convert received datetime to correct format
            received_datetime = datetime.strptime(email.get('receivedDateTime', ''), "%Y-%m-%dT%H:%M:%SZ").strftime('%Y-%m-%d %H:%M:%S')

            # Update email_data tuple to include attachment_names
            attachments = email.get('attachments', [])
            attachment_names = ', '.join([attachment.get('name', '') for attachment in attachments])
            logger.info(f"Attachment names for email '{email.get('id', '')}': {attachment_names}")
            email_data = (
                email.get('id', ''),
                email.get('subject', ''),
                email.get('from', {}).get('emailAddress', {}).get('address', ''),
                received_datetime,
                attachment_names
            )
            insert_email_query = "INSERT INTO emails (id, subject, sender_email, received_datetime, attachment_names) VALUES (%s, %s, %s, %s, %s)"
            try:
                cursor.execute(insert_email_query, email_data)
            except mysql.connector.Error as err:
                logger.error(f"Error inserting email into database: {err}")
            
            # Download, extract, and save attachments
            if 'attachments' in email:
                for attachment in email['attachments']:
                    attachment_id = attachment.get('id', '')
                    attachment_name = attachment.get('name', '')
                    attachment_content, attachment_filename = download_attachment(email.get('id', ''), attachment_id, attachment_name, headers, SHARED_MAILBOX_EMAIL)
                    if attachment_content:
                        insert_attachment(cursor, email.get('id', ''), attachment_name, attachment_content)

        db_connection.commit()
        cursor.close()

if __name__ == "__main__":
    main()
