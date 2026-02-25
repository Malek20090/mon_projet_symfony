<?php

namespace App\Service;

use App\Entity\Cours;
use Dompdf\Dompdf;
use Dompdf\Options;

class CoursPdfService
{
    public function renderCoursPdf(Cours $cours): string
    {
        $html = $this->buildHtml($cours);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildHtml(Cours $cours): string
    {
        $titre = htmlspecialchars($cours->getTitre());
        $contenu = nl2br(htmlspecialchars($cours->getContenuTexte()));
        
        // Handle media if exists
        $mediaHtml = '';
        if ($cours->getUrlMedia()) {
            if ($cours->getTypeMedia() === 'video') {
                $mediaHtml = '
                <div style="margin-top: 30px; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; color: white;">
                    <h3 style="color: white; margin-top: 0;">🎥 Vidéo</h3>
                    <p style="margin-bottom: 0;">' . htmlspecialchars($cours->getUrlMedia()) . '</p>
                </div>';
            } elseif ($cours->getTypeMedia() === 'image') {
                $mediaHtml = '
                <div style="margin-top: 30px; padding: 20px; background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border-radius: 12px; color: white;">
                    <h3 style="color: white; margin-top: 0;">🖼️ Image</h3>
                    <p style="margin-bottom: 0;">' . htmlspecialchars($cours->getUrlMedia()) . '</p>
                </div>';
            }
        }

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>{$titre}</title>
            <style>
                @page {
                    margin: 40px 50px;
                    margin-bottom: 60px;
                }
                body {
                    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
                    font-size: 13px;
                    line-height: 1.8;
                    color: #2c3e50;
                    background-color: #f9f9f9;
                }
                .container {
                    background: white;
                    padding: 50px;
                    border-radius: 0;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    text-align: center;
                    margin-bottom: 40px;
                    padding-bottom: 30px;
                    border-bottom: 3px solid #3498db;
                }
                h1 {
                    color: #2c3e50;
                    font-size: 32px;
                    font-weight: 700;
                    margin-bottom: 15px;
                    letter-spacing: -0.5px;
                }
                .subtitle {
                    color: #7f8c8d;
                    font-size: 14px;
                    font-weight: 300;
                }
                .content {
                    text-align: justify;
                    margin-bottom: 30px;
                }
                .content-title {
                    color: #3498db;
                    font-size: 20px;
                    font-weight: 600;
                    margin-bottom: 20px;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #ecf0f1;
                }
                .footer {
                    position: fixed;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    text-align: center;
                    font-size: 11px;
                    color: #95a5a6;
                    padding: 15px 0;
                    border-top: 2px solid #3498db;
                    background: white;
                }
                p {
                    margin-bottom: 15px;
                }
                strong {
                    color: #2c3e50;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>{$titre}</h1>
                </div>
                
                <div class='content'>
                    <div class='content-title'>Contenu du cours</div>
                    {$contenu}
                </div>
                
                {$mediaHtml}
                
                <div class='footer'>
                    <p>© Decide$ - Plateforme d'éducation financière</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
