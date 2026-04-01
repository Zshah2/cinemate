from pathlib import Path
import shutil 
import kagglehub

dataset_dir = Path(kagglehub.dataset_path('imdb/imdb-movies-2000-2020'))
project_dir = Path(__file__).resolve().parent
csv_files = list[Path](dataset_dir.rglob('*.csv'))

for csv in csv_files:
    shutil.copy(csv, project_dir / csv.name)

print("Download complete")