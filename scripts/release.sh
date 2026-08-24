#!/usr/bin/env bash
# Veröffentlicht eine neue Version und hält Git- und Docker-Tags synchron.
#
# Nutzung:  bash scripts/release.sh dev-0.18 "Kurze Commit-Nachricht"
#
# Ablauf: committen -> Git-Tag -> Smoke-Test -> Docker bauen+pushen -> Git pushen.
# Bricht ab, wenn der Smoke-Test fehlschlägt (kein kaputtes Image wird veröffentlicht).
set -euo pipefail

VERSION="${1:?Version angeben, z.B. dev-0.18}"
MSG="${2:-Release $VERSION}"
REPO=mkrasselt1/schulquizz
IMAGE="$REPO:$VERSION"

echo "== 1. Änderungen committen =="
git add -A
git commit -m "$MSG" || echo "  (nichts zu committen)"

echo "== 2. Git-Tag $VERSION =="
git tag -a "$VERSION" -m "Schulquizz $VERSION (= Docker $IMAGE)"

echo "== 3. Smoke-Test (echtes Image + MariaDB) =="
IMAGE="$IMAGE" bash scripts/smoke-test.sh

echo "== 4. Docker bauen + pushen ($VERSION + latest) =="
docker build --network=host --target standalone -t "$IMAGE" -t "$REPO:latest" .
docker push "$IMAGE"
docker push "$REPO:latest"

echo "== 5. Git pushen (Branch + Tag) =="
git push origin main
git push origin "$VERSION"

echo
echo "✅ $VERSION veröffentlicht – Git-Tag und Docker-Tag sind synchron."
