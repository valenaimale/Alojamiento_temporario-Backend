# Prueba de carga (k6)

Mide el tiempo de respuesta del backend con varios usuarios a la vez (RNF-13). Usa [k6](https://k6.io), la herramienta de load testing que recomienda la cátedra.

No es parte de PHPUnit ni del CI: se corre a mano, cuando se quiere medir (por ejemplo, antes y después de un cambio que afecta el rendimiento). Le hace peticiones reales al servidor indicado.

## Qué hace `carga.js`

| Escenario | Usuarios a la vez | Duración |
|---|---|---|
| `un_usuario` | 1 | 30 s (la referencia, sin carga) |
| `diez_usuarios` | 10 | 30 s (el objetivo del RNF-13) |

Cada usuario repite, sin pausa: `GET /estado` (consulta la base) y `POST /iniciar-sesion` con un usuario que no existe (busca en la base y responde `401`). **Ninguna petición escribe en la base**, así que se puede correr contra producción.

El objetivo está escrito en el script (`thresholds`): **con 10 usuarios, p95 < 800 ms y menos de 0,1 % de errores**. Al final, k6 muestra ✓ o ✗ para cada uno.

## Cómo correrlo

Con **Docker** abierto (no hace falta instalar k6), desde la raíz del backend.

**En PowerShell:**
```powershell
Get-Content tests/carga/carga.js | docker run --rm -i grafana/k6 run -
```

**En Git Bash:**
```bash
docker run --rm -i grafana/k6 run - < tests/carga/carga.js
```

Tarda poco más de un minuto. Por defecto apunta a **producción a través de Vercel**, el mismo camino que usa el navegador.

**Contra el backend local** (`php -S localhost:8000 -t index` o la demo de `docker compose`):
```powershell
Get-Content tests/carga/carga.js | docker run --rm -i -e URL_BASE=http://host.docker.internal:8000 grafana/k6 run -
```
(`host.docker.internal` es "tu máquina" vista desde adentro del contenedor de k6.)

**Antes de correrlo contra producción**, verificar que Aiven esté encendido: si no, todas las peticiones fallan.

## Cómo leer el resultado

```
http_req_duration{scenario:diez_usuarios}
✗ 'p(95)<800' p(95)=4.4s          ← el objetivo: ✓ se cumple, ✗ no se cumple
http_req_failed{scenario:diez_usuarios}
✓ 'rate<0.001' rate=0.00%         ← errores

http_req_duration
  { scenario:diez_usuarios }....: avg=4.1s  med=4.3s  p(95)=4.4s ...
  { scenario:un_usuario }.......: avg=645ms med=620ms p(95)=724ms ...
http_reqs.......................: 128   1.83/s     ← peticiones totales y por segundo
```

- **p95:** el 95 % de las peticiones tardó menos que ese valor. Se usa en lugar del promedio porque el promedio esconde a los usuarios que esperan mucho.
- **`checks`:** que cada respuesta sea la esperada (`/estado` con la base funcionando, el login con `401`).
- Si algún objetivo no se cumple, k6 termina con el mensaje `thresholds ... have been crossed` y con **código de salida 99** (PowerShell lo muestra como `Exit code 99`). No es un error de la prueba: es k6 avisando que no se alcanzó el objetivo.

## Resultados

| Fecha | Contra | 1 usuario (p95) | 10 usuarios (p95) | Errores | ¿Cumple? |
|---|---|---|---|---|---|
| 17/10/2026 | Producción (`php -S`, 1 réplica) | 725 ms | 4,4 s | 0 % | ✗ |
| 17/10/2026 (repetición) | Producción (`php -S`, 1 réplica) | 710 ms | 4,41 s | 0 % | ✗ |

**Por qué no cumple:** `php -S` atiende **una petición por vez**, así que con 10 usuarios cada uno espera en la cola a los demás (unas 2,7 peticiones por segundo, se mida con 1 o con 10 usuarios). Además, unos 440 ms de cada petición son abrir la conexión cifrada con la base. El plan es reemplazar `php -S` por Nginx + PHP-FPM, que atiende en paralelo, y repetir esta medición (ver el RNF-13).
