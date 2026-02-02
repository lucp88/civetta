#!/bin/bash

HOST="web0169.zxcs.nl"
USER="u204409p449846"
REMOTE_PATH="public_html"
LOCAL_PATH="/Users/lucaspeek/Documents/PeekDynamics/civetta/civetta"

echo "🚀 Deploying to $HOST..."
echo "📁 Local: $LOCAL_PATH"
echo "📁 Remote: $REMOTE_PATH"
echo ""

lftp << EOF
set net:timeout 30
set net:max-retries 3
set ssl:verify-certificate no
open ftp://$USER@$HOST
cd $REMOTE_PATH
lcd $LOCAL_PATH
mirror -R --only-newer --verbose \
    --exclude .git/ \
    --exclude .gitignore \
    --exclude .claude/ \
    --exclude .vscode/ \
    --exclude node_modules/ \
    --exclude deploy.sh \
    --exclude-glob *.md \
    --exclude .env \
    --exclude .env.example \
    --exclude .DS_Store \
    --exclude docs/ \
    ./ ./
bye
EOF

echo ""
echo "✅ Deploy complete!"
