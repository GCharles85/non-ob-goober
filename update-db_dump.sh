cat > /home/ec2-user/update-db-dump.sh << 'EOF'
#!/bin/bash

# Log output
exec > >(tee /tmp/db-dump-$(date +%Y%m%d%H%M%S).log) 2>&1

# Get environment variables
if [ -f /opt/elasticbeanstalk/deployment/env ]; then
  source /opt/elasticbeanstalk/deployment/env
fi

echo "Creating database dump and uploading to S3..."

# Create the dump and upload to S3
mysqldump -u"${DB_USERNAME}" -p"${DB_PASSWORD}" --opt --single-transaction --routines --triggers "${DB_NAME}" | aws s3 cp - s3://gooberbucketgc6788/database_dumps/latest-database-dump.sql

echo "Database dump completed and uploaded to S3."
EOF

chmod +x /home/ec2-user/update-db-dump.sh
chown root:apache /home/ec2-user/update-db-dump.sh