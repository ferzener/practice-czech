# generate_audios.py
import argparse
import json
import os
import time
import hashlib
from typing import Dict
from unidecode import unidecode
import boto3
from botocore.exceptions import ClientError

def slugify(text: str) -> str:
    """
    Gera um nome de arquivo seguro:
    - translitera para ASCII (á -> a, č -> c, etc.)
    - troca espaços por underscore
    - remove caracteres problemáticos
    """
    ascii_text = unidecode(text)
    safe = []
    for ch in ascii_text:
        if ch.isalnum() or ch in ("_", "-", "."):
            safe.append(ch)
        elif ch.isspace():
            safe.append("_")
        else:
            # ignora outros sinais
            pass
    out = "".join(safe).strip("_")
    return out or "audio"

def synth_to_mp3(polly, text: str, outpath: str, voice: str, engine: str, language_code: str):
    resp = polly.synthesize_speech(
        Engine=engine,
        LanguageCode=language_code,
        VoiceId=voice,
        OutputFormat="mp3",
        Text=text,
        TextType="text",
    )
    with open(outpath, "wb") as f:
        f.write(resp["AudioStream"].read())

def main():
    ap = argparse.ArgumentParser(description="Gera um MP3 para cada key do JSON (palavras em tcheco).")
    ap.add_argument("--json", default="words.json", help="Caminho do JSON de entrada (default: words.json)")
    ap.add_argument("--outdir", default="audios", help="Diretório de saída (default: audios)")
    ap.add_argument("--region", default="us-east-1", help="Região da AWS (default: us-east-1)")
    ap.add_argument("--voice", default="Jitka", help="Voz da AWS Polly (default: Jitka)")
    ap.add_argument("--engine", default="neural", choices=["neural", "standard"], help="Engine Polly (default: neural)")
    ap.add_argument("--lang", default="cs-CZ", help="LanguageCode (default: cs-CZ)")
    ap.add_argument("--force", action="store_true", help="Regerar mesmo que o arquivo já exista")
    ap.add_argument("--prefix", default="", help="Prefixo no nome do arquivo (opcional)")
    ap.add_argument("--suffix", default="", help="Sufixo no nome do arquivo (opcional)")
    ap.add_argument("--sleep", type=float, default=0.0, help="Pausa (segundos) entre requisições para evitar throttling")
    args = ap.parse_args()

    # carrega JSON (dict: palavra -> lista de traduções)
    with open(args.json, "r", encoding="utf-8") as f:
        data: Dict[str, list] = json.load(f)

    os.makedirs(args.outdir, exist_ok=True)

    polly = boto3.client("polly", region_name=args.region)

    total = len(data)
    ok = 0
    skipped = 0
    failed = 0

    print(f"🎧 Gerando {total} áudios para {args.voice} ({args.lang}, {args.engine}) em '{args.outdir}'\n")

    for i, word in enumerate(data.keys(), start=1):
        # texto que será falado é a própria key (palavra em tcheco)
        text = word

        # nome de arquivo seguro
        base = slugify(word)
        # para evitar colisões de nomes iguais após transliteração, inclui um hash curtinho
        short_hash = hashlib.sha1(word.encode("utf-8")).hexdigest()[:6]
        filename = f"{args.prefix}{base}-{short_hash}{args.suffix}.mp3" if base else f"word-{short_hash}.mp3"
        outpath = os.path.join(args.outdir, filename)

        if not args.force and os.path.exists(outpath):
            print(f"[{i}/{total}] ⏭️  Já existe, ignorando: {outpath}")
            skipped += 1
            continue

        try:
            synth_to_mp3(
                polly=polly,
                text=text,
                outpath=outpath,
                voice=args.voice,
                engine=args.engine,
                language_code=args.lang,
            )
            print(f"[{i}/{total}] ✅ {word}  →  {outpath}")
            ok += 1
        except ClientError as e:
            code = e.response.get("Error", {}).get("Code")
            msg = e.response.get("Error", {}).get("Message")
            print(f"[{i}/{total}] ❌ Falha em '{word}': {code} - {msg}")
            failed += 1
            # Throttling: aguarda um pouco e segue
            if code and "Throttl" in code:
                time.sleep(max(args.sleep, 1.5))
        except Exception as e:
            print(f"[{i}/{total}] ❌ Falha em '{word}': {e}")
            failed += 1

        if args.sleep > 0:
            time.sleep(args.sleep)

    print(f"\nConcluído. OK: {ok}  |  Ignorados: {skipped}  |  Falhas: {failed}")

if __name__ == "__main__":
    main()