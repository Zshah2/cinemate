set -euo pipefail

cd "$(dirname "$0")"

CSV="IMDB Movies 2000 - 2020.csv"

if [ ! -d venv ]; then 
    python3 -m venv venv
fi
source venv/bin/activate
pip install -r requirements.txt


if [ ! -f "$CSV" ]; then
    python download_data.py
fi

php -S localhost:8000
