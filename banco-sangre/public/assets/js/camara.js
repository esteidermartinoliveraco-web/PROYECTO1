/**
 * Módulo de Captura y Compresión de Formularios Pre-Donación
 * Optimizado para navegadores móviles (Android / iOS) y escáneres de escritorio.
 */
export class CapturaFormulario {
    constructor(opciones = {}) {
        this.maxAncho = opciones.maxAncho || 1600;
        this.calidadJpeg = opciones.calidadJpeg || 0.82;
        this.archivosProcesados = {
            anverso: null,
            reverso: null
        };
    }

    /**
     * Inicializa los listeners en los inputs file y contenedores de vista previa.
     * @param {Object} elementos - Referencias DOM { inputAnverso, previewAnverso, inputReverso, previewReverso, btnEnviar, form }
     */
    inicializar(elementos) {
        this.el = elementos;

        if (this.el.inputAnverso) {
            this.el.inputAnverso.addEventListener('change', (e) => {
                this.procesarSeleccion(e.target.files[0], 'anverso', this.el.previewAnverso);
            });
        }

        if (this.el.inputReverso) {
            this.el.inputReverso.addEventListener('change', (e) => {
                this.procesarSeleccion(e.target.files[0], 'reverso', this.el.previewReverso);
            });
        }

        if (this.el.form) {
            this.el.form.addEventListener('submit', (e) => this.enviarEntrevista(e));
        }
    }

    /**
     * Procesa el archivo seleccionado: si es PDF lo conserva intacto; si es imagen la comprime en Canvas.
     */
    async procesarSeleccion(archivo, lado, contenedorPreview) {
        if (!archivo) return;

        try {
            if (archivo.type === 'application/pdf') {
                if (archivo.size > 15 * 1024 * 1024) {
                    throw new Error('El archivo PDF no debe superar los 15 MB.');
                }
                this.archivosProcesados[lado] = archivo;
                this.renderizarPreviewPdf(archivo.name, contenedorPreview, lado);
                return;
            }

            if (!archivo.type.startsWith('image/')) {
                throw new Error('Formato no permitido. Tome una fotografía o suba un PDF.');
            }

            const blobComprimido = await this.comprimirImagenCanvas(archivo);
            const archivoFinal = new File([blobComprimido], `formulario_${lado}_${Date.now()}.jpg`, {
                type: 'image/jpeg'
            });

            this.archivosProcesados[lado] = archivoFinal;
            this.renderizarPreviewImagen(blobComprimido, contenedorPreview, lado);
        } catch (error) {
            alert(error.message);
            this.limpiarLado(lado, contenedorPreview);
        }
    }

    /**
     * Redimensiona y comprime la fotografía usando HTML5 Canvas.
     */
    comprimirImagenCanvas(archivo) {
        return new Promise((resolve, reject) => {
            const lector = new FileReader();
            lector.onload = (evento) => {
                const img = new Image();
                img.onload = () => {
                    let ancho = img.width;
                    let alto = img.height;

                    if (ancho > this.maxAncho) {
                        alto = Math.round((alto * this.maxAncho) / ancho);
                        ancho = this.maxAncho;
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width = ancho;
                    canvas.height = alto;

                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillRect(0, 0, ancho, alto);
                    ctx.drawImage(img, 0, 0, ancho, alto);

                    canvas.toBlob(
                        (blob) => {
                            if (!blob) {
                                reject(new Error('No se pudo procesar la fotografía en el dispositivo.'));
                                return;
                            }
                            resolve(blob);
                        },
                        'image/jpeg',
                        this.calidadJpeg
                    );
                };
                img.onerror = () => reject(new Error('La imagen capturada está dañada.'));
                img.src = evento.target.result;
            };
            lector.onerror = () => reject(new Error('Error al leer el archivo desde la cámara.'));
            lector.readAsDataURL(archivo);
        });
    }

    renderizarPreviewImagen(blob, contenedor, lado) {
        if (!contenedor) return;
        const urlTemporal = URL.createObjectURL(blob);
        const pesoKb = Math.round(blob.size / 1024);

        contenedor.innerHTML = `
            <div class="preview-card">
                <img src="${urlTemporal}" alt="Vista previa ${lado}" style="max-width: 100%; max-height: 240px; border-radius: 6px; border: 1px solid #cbd5e1;" />
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px; font-size: 0.85rem;">
                    <span>Listo (${pesoKb} KB)</span>
                    <button type="button" class="btn-quitar" data-lado="${lado}">Quitar</button>
                </div>
            </div>
        `;

        contenedor.querySelector('.btn-quitar').addEventListener('click', () => {
            URL.revokeObjectURL(urlTemporal);
            this.limpiarLado(lado, contenedor);
        });
    }

    renderizarPreviewPdf(nombreArchivo, contenedor, lado) {
        if (!contenedor) return;
        contenedor.innerHTML = `
            <div class="preview-card" style="padding: 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px;">
                <strong>PDF adjunto:</strong> ${nombreArchivo}
                <button type="button" class="btn-quitar" data-lado="${lado}" style="margin-left: 10px;">Quitar</button>
            </div>
        `;
        contenedor.querySelector('.btn-quitar').addEventListener('click', () => {
            this.limpiarLado(lado, contenedor);
        });
    }

    limpiarLado(lado, contenedor) {
        this.archivosProcesados[lado] = null;
        if (lado === 'anverso' && this.el.inputAnverso) this.el.inputAnverso.value = '';
        if (lado === 'reverso' && this.el.inputReverso) this.el.inputReverso.value = '';
        if (contenedor) contenedor.innerHTML = '';
    }

    /**
     * Envía los signos vitales y las fotos comprimidas vía FormData a /api/entrevistas.
     */
    async enviarEntrevista(evento) {
        evento.preventDefault();

        if (!this.archivosProcesados.anverso) {
            alert('Debe capturar o adjuntar al menos la fotografía del anverso del formulario firmado.');
            return;
        }

        const formData = new FormData(this.el.form);
        formData.set('anverso', this.archivosProcesados.anverso);

        if (this.archivosProcesados.reverso) {
            formData.set('reverso', this.archivosProcesados.reverso);
        } else {
            formData.delete('reverso');
        }

        if (this.el.btnEnviar) {
            this.el.btnEnviar.disabled = true;
            this.el.btnEnviar.textContent = 'Subiendo formulario...';
        }

        try {
            const respuesta = await fetch('/api/entrevistas', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const resultado = await respuesta.json();
            if (!respuesta.ok || !resultado.ok) {
                throw new Error(resultado.error || 'Error al registrar la entrevista.');
            }

            alert('Entrevista pre-donación registrada correctamente. Pendiente de aprobación del Supervisor.');
            this.el.form.reset();
            this.limpiarLado('anverso', this.el.previewAnverso);
            this.limpiarLado('reverso', this.el.previewReverso);
        } catch (error) {
            alert(error.message);
        } finally {
            if (this.el.btnEnviar) {
                this.el.btnEnviar.disabled = false;
                this.el.btnEnviar.textContent = 'Guardar Entrevista Pre-Donación';
            }
        }
    }
}