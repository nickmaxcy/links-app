FROM nginx:latest

RUN apt-get update && \
    apt-get install -y --no-install-recommends python3-pip && \
    pip install --break-system-packages certbot certbot-dns-dnsmadeeasy && \
    rm -rf /var/lib/apt/lists/*

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
