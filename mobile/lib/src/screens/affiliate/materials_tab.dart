import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../../api/models.dart';
import '../../ui/widgets.dart';

/// Photos, videos and post captions the store prepared for affiliates.
class MaterialsTab extends StatelessWidget {
  const MaterialsTab({super.key});

  static const _icons = <String, IconData>{
    'image': Icons.image_outlined,
    'video': Icons.movie_outlined,
    'document': Icons.description_outlined,
    'archive': Icons.folder_zip_outlined,
    'text': Icons.notes,
  };

  static const _extensions = <String, String>{
    'image/jpeg': 'jpg',
    'image/png': 'png',
    'image/webp': 'webp',
    'image/gif': 'gif',
    'video/mp4': 'mp4',
    'application/pdf': 'pdf',
    'application/zip': 'zip',
  };

  @override
  Widget build(BuildContext context) {
    return LoadView<List<MarketingAsset>>(
      load: () => apiOf(context).marketingAssets(),
      builder: (context, assets, _) {
        if (assets.isEmpty) {
          return const PageList(
            children: [
              EmptyState(
                icon: Icons.photo_library_outlined,
                title: 'No materials yet',
                body: 'Photos and ready-made posts from the store will show up here.',
              ),
            ],
          );
        }
        return PageList(
          children: [
            for (final a in assets)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: _AssetCard(asset: a),
              ),
          ],
        );
      },
    );
  }

  /// Downloads the file with the signed-in token and opens the share sheet,
  /// so it can be saved or posted straight to another app.
  static Future<void> shareFile(
    BuildContext context,
    MarketingAsset asset,
  ) async {
    try {
      final (bytes, type) = await apiOf(context).marketingAssetFile(asset.id);
      final mime = type?.split(';').first.trim();
      final ext = _extensions[mime] ?? 'bin';
      final name =
          '${asset.title.replaceAll(RegExp(r'[^A-Za-z0-9]+'), '-')}.$ext';
      await SharePlus.instance.share(
        ShareParams(
          files: [XFile.fromData(bytes, mimeType: mime, name: name)],
          fileNameOverrides: [name],
          text: asset.caption,
        ),
      );
    } catch (e) {
      if (context.mounted) showSnack(context, errorText(e));
    }
  }
}

class _AssetCard extends StatelessWidget {
  const _AssetCard({required this.asset});
  final MarketingAsset asset;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final api = apiOf(context);
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (asset.kind == 'image' && asset.hasFile)
            AspectRatio(
              aspectRatio: 16 / 10,
              child: Image.network(
                api.marketingAssetPreviewUrl(asset.id),
                headers: api.client.authHeaders,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) =>
                    const Icon(Icons.broken_image_outlined),
              ),
            ),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(
                      MaterialsTab._icons[asset.kind] ?? Icons.attach_file,
                      size: 18,
                      color: theme.colorScheme.primary,
                    ),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        asset.title,
                        style: theme.textTheme.titleSmall,
                      ),
                    ),
                  ],
                ),
                if (asset.campaignName != null)
                  Text(asset.campaignName!, style: theme.textTheme.bodySmall),
                if (asset.description != null) ...[
                  const SizedBox(height: 4),
                  Text(asset.description!),
                ],
                if (asset.caption != null) ...[
                  const SizedBox(height: 8),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Text(asset.caption!),
                  ),
                ],
              ],
            ),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              if (asset.caption != null)
                TextButton.icon(
                  icon: const Icon(Icons.copy_rounded, size: 18),
                  label: const Text('Copy text'),
                  onPressed: () => copyText(
                    context,
                    asset.caption!,
                    label: 'Caption copied',
                  ),
                ),
              if (asset.hasFile)
                TextButton.icon(
                  icon: const Icon(Icons.share_rounded, size: 18),
                  label: const Text('Share'),
                  onPressed: () => MaterialsTab.shareFile(context, asset),
                )
              else if (asset.caption != null)
                TextButton.icon(
                  icon: const Icon(Icons.share_rounded, size: 18),
                  label: const Text('Share'),
                  onPressed: () => shareText(asset.caption!),
                ),
              const SizedBox(width: 6),
            ],
          ),
        ],
      ),
    );
  }
}
